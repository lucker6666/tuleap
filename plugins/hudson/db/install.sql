DROP TABLE IF EXISTS plugin_hudson_job;
CREATE TABLE plugin_hudson_job (
  job_id int(11) UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT ,
  group_id int(11) NOT NULL ,
  job_url varchar(255) NOT NULL ,
);

-- Enable service for project 1 and 100
INSERT INTO service(group_id, label, description, short_name, link, is_active, is_used, scope, rank) VALUES ( 100 , 'plugin_hudson:service_lbl_key' , 'plugin_hudson:service_desc_key' , 'hudson', '/plugins/hudson/?group_id=$group_id', 1 , 1 , 'system',  220 );
INSERT INTO service(group_id, label, description, short_name, link, is_active, is_used, scope, rank) VALUES ( 1   , 'plugin_hudson:service_lbl_key' , 'plugin_hudson:service_desc_key' , 'hudson', '/plugins/hudson/?group_id=1', 1 , 0 , 'system',  220 );

-- Create service for all other projects (but disabled)
INSERT INTO service(group_id, label, description, short_name, link, is_active, is_used, scope, rank)
SELECT DISTINCT group_id , 'plugin_hudson:service_lbl_key' , 'plugin_hudson:service_desc_key' , 'hudson', CONCAT('/plugins/hudson/?group_id=', group_id), 1 , 0 , 'system',  220
FROM service
WHERE group_id NOT IN (SELECT group_id
    FROM service
    WHERE short_name
    LIKE 'hudson');