<?php
/**
 * Copyright (c) Enalean, 2012. All Rights Reserved.
 *
 * This file is a part of Tuleap.
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
 * along with Tuleap. If not, see <http://www.gnu.org/licenses/>.
 */
require_once dirname(__FILE__).'/../../../bootstrap.php';

class AgileDashboard_Milestone_MilestoneReportCriterionProviderTest extends TuleapTestCase {

    public function setUp() {
        parent::setUp();
        $this->task_tracker          = aTracker()->withId('task')->build();
        $this->options_provider      = mock('AgileDashboard_Milestone_MilestoneReportCriterionOptionsProvider');
        $this->milestone_id_provider = mock('AgileDashboard_Milestone_SelectedMilestoneIdProvider');

        $this->provider = new AgileDashboard_Milestone_MilestoneReportCriterionProvider(
            $this->milestone_id_provider,
            $this->options_provider
        );
    }

    public function itReturnsNullWhenNoOptions() {
        stub($this->options_provider)->getSelectboxOptions($this->task_tracker, '*')->returns(array());
        $this->assertEqual($this->provider->getCriterion($this->task_tracker), null);
    }

    public function itReturnsASelectBox() {
        stub($this->options_provider)->getSelectboxOptions($this->task_tracker, '*')->returns(array('<option>1','<option>2'));
        $this->assertPattern('/<select name="additional_criteria\[agiledashboard_milestone\]"/', $this->provider->getCriterion($this->task_tracker));
    }

    public function itSelectsTheGivenMilestone() {
        stub($this->milestone_id_provider)->getMilestoneId()->returns('whatever');

        expect($this->options_provider)->getSelectboxOptions($this->task_tracker, 'whatever')->once();

        $this->provider->getCriterion($this->task_tracker);
    }
}