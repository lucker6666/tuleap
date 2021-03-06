<?php
/**
 * Copyright (c) Enalean, 2013. All Rights Reserved.
 *
 * Tuleap is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Tuleap is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Tuleap; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 */

class User_LoginManagerTest extends TuleapTestCase {
    private $event_manager;
    private $user_manager;
    private $login_manager;

    public function setUp() {
        parent::setUp();
        Config::store();
        $this->event_manager = mock('EventManager');
        $this->user_manager  = mock('UserManager');
        $this->login_manager = new User_LoginManager($this->event_manager, $this->user_manager);
    }

    public function tearDown() {
        Config::restore();
        parent::tearDown();
    }

    public function itDelegatesAuthenticationToPlugin() {
        stub($this->user_manager)->getUserByUserName()->returns(
            aUser()->withPassword('password')->withStatus(PFUser::STATUS_ACTIVE)->build()
        );

        expect($this->event_manager)->processEvent()->count(2);
        expect($this->event_manager)->processEvent(
            Event::SESSION_BEFORE_LOGIN,
            array(
                'loginname' => 'john',
                'passwd'  => 'password',
                'auth_success' => false,
                'auth_user_id' => null,
                'auth_user_status' => null
            )
        )->at(0);

        $this->login_manager->authenticate('john', 'password');
    }

    public function itUsesDbAuthIfPluginDoesntAnswer() {
        stub($this->user_manager)->getUserByUserName()->returns(
            aUser()->withPassword('password')->withStatus(PFUser::STATUS_ACTIVE)->build()
        );

        expect($this->user_manager)->getUserByUserName('john')->once();
        $this->login_manager->authenticate('john', 'password');
    }

    public function itThrowsAnExceptionWhenUserIsNotFound() {
        $this->expectException('User_InvalidPasswordException');
        stub($this->user_manager)->getUserByUserName()->returns(null);
        $this->login_manager->authenticate('john', 'password');
    }

    public function itThrowsAnExceptionWhenPasswordIsWrong() {
        $this->expectException('User_InvalidPasswordWithUserException');
        stub($this->user_manager)->getUserByUserName()->returns(aUser()->withPassword('pa')->build());
        $this->login_manager->authenticate('john', 'password');
    }

    public function itThrowsAnExceptionWithUserWhenPasswordIsWrong() {
        $exception_catched = false;
        $user = aUser()->withPassword('pa')->build();
        stub($this->user_manager)->getUserByUserName()->returns($user);
        try {
            $this->login_manager->authenticate('john', 'password');
        } catch(User_InvalidPasswordWithUserException $exception) {
            $this->assertEqual($exception->getUser(), $user);
            $exception_catched = true;
        }
        $this->assertTrue($exception_catched);
    }

    public function itAsksPluginIfDbAuthIsAuthorizedForUser() {
        $user = aUser()->withPassword('password')->withStatus(PFUser::STATUS_ACTIVE)->build();
        stub($this->user_manager)->getUserByUserName()->returns($user);

        expect($this->event_manager)->processEvent()->count(2);
        expect($this->event_manager)->processEvent(
            Event::SESSION_AFTER_LOGIN,
            array(
                'user' => $user,
                'allow_codendi_login'  => true,
            )
        )->at(1);

        $this->login_manager->authenticate('john', 'password');
    }

    public function itReturnsTheUserOnSuccess() {
        $user = aUser()->withPassword('password')->withStatus(PFUser::STATUS_ACTIVE)->build();
        stub($this->user_manager)->getUserByUserName()->returns($user);
        $this->assertEqual(
            $this->login_manager->authenticate('john', 'password'),
            $user
        );
    }

    public function itRaisesAnExceptionWhenPasswordExpired() {
        $this->expectException('User_PasswordExpiredException');
        Config::set('sys_password_lifetime', 10);
        stub($this->user_manager)->getUserByUserName()->returns(
            aUser()
                ->withPassword('password')
                ->withStatus(PFUser::STATUS_ACTIVE)
                ->withLastPasswordUpdate(strtotime('15 days ago'))
                ->build()
        );
        $this->login_manager->authenticate('john', 'password');
    }

    public function itThrowsAnExceptionWithUserWhenPasswordExpired() {
        $exception_catched = false;
        Config::set('sys_password_lifetime', 10);
        $user = aUser()
            ->withPassword('password')
            ->withStatus(PFUser::STATUS_ACTIVE)
            ->withLastPasswordUpdate(strtotime('15 days ago'))
            ->build();
        stub($this->user_manager)->getUserByUserName()->returns($user);
        try {
            $this->login_manager->authenticate('john', 'password');
        } catch(User_PasswordExpiredException $exception) {
            $this->assertEqual($exception->getUser(), $user);
            $exception_catched = true;
        }
        $this->assertTrue($exception_catched);
    }
}

class User_LoginManager_validateAndSetCurrentUserTest extends TuleapTestCase {
    private $event_manager;
    private $user_manager;
    private $login_manager;

    public function setUp() {
        parent::setUp();
        Config::store();
        $this->event_manager = mock('EventManager');
        $this->user_manager  = mock('UserManager');
        $this->login_manager = new User_LoginManager($this->event_manager, $this->user_manager);
    }

    public function tearDown() {
        Config::restore();
        parent::tearDown();
    }

    public function itPersistsValidUser() {
        $user = aUser()->withStatus(PFUser::STATUS_ACTIVE)->build();

        expect($this->user_manager)->setCurrentUser($user)->once();

        $this->login_manager->validateAndSetCurrentUser($user);
    }

    public function itDoesntPersistUserWithInvalidStatus() {
        $this->expectException();
        $user = aUser()->withStatus(PFUser::STATUS_DELETED)->build();

        expect($this->user_manager)->setCurrentUser($user)->never();

        $this->login_manager->validateAndSetCurrentUser($user);
    }
}

class User_LoginManagerPluginsTest extends TuleapTestCase {
    /** @var EventManager */
    private $event_manager;
    private $user_manager;
    private $login_manager;

    public function setUp() {
        parent::setUp();
        $this->event_manager = new EventManager();
        $this->user_manager  = mock('UserManager');
        $this->login_manager = new User_LoginManager($this->event_manager, $this->user_manager);
    }

    public function authenticationSucceed(array $params) {
        $params['auth_success'] = true;
        $params['auth_user_id'] = 105;
    }

    public function itDoesntUseDbAuthIfPluginAuthenticate() {
        stub($this->user_manager)->getUserById()->returns(
            aUser()->withStatus(PFUser::STATUS_ACTIVE)->build()
        );
        $this->event_manager->addListener(
            Event::SESSION_BEFORE_LOGIN,
            $this, 
            'authenticationSucceed',
            false,
            0
        );

        expect($this->user_manager)->getUserByUserName()->never();
        $this->login_manager->authenticate('john', 'password');
    }

    public function itInstanciateTheUserWithPluginId() {
        expect($this->user_manager)->getUserById(105)->once();
        stub($this->user_manager)->getUserById()->returns(
            aUser()->withStatus(PFUser::STATUS_ACTIVE)->build()
        );
        $this->event_manager->addListener(
            Event::SESSION_BEFORE_LOGIN,
            $this,
            'authenticationSucceed',
            false,
            0
        );

        expect($this->user_manager)->getUserByUserName()->never();
        $this->login_manager->authenticate('john', 'password');
    }

    public function itRaisesAnExceptionIfPluginForbidLogin() {
        $this->expectException('User_InvalidPasswordWithUserException');
        $user = aUser()->withPassword('password')->withStatus(PFUser::STATUS_ACTIVE)->build();
        stub($this->user_manager)->getUserByUserName()->returns($user);

         $this->event_manager->addListener(
            Event::SESSION_AFTER_LOGIN,
            $this,
            'refuseLogin',
            false,
            0
        );

        $this->login_manager->authenticate('john', 'password');
    }

    public function refuseLogin(array $params) {
        $params['allow_codendi_login'] = false;
    }
}

?>
