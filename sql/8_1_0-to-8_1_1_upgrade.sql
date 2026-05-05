--
--  Comment Meta Language Constructs:
--
--  #IfNotTable
--    argument: table_name
--    behavior: if the table_name does not exist,  the block will be executed

--  #IfTable
--    argument: table_name
--    behavior: if the table_name does exist, the block will be executed

--  #IfColumn
--    arguments: table_name colname
--    behavior:  if the table and column exist,  the block will be executed

--  #IfMissingColumn
--    arguments: table_name colname
--    behavior:  if the table exists but the column does not,  the block will be executed

--  #IfNotColumnType
--    arguments: table_name colname value
--    behavior:  If the table table_name does not have a column colname with a data type equal to value, then the block will be executed

--  #IfNotColumnTypeDefault
--    arguments: table_name colname value value2
--    behavior:  If the table table_name does not have a column colname with a data type equal to value and a default equal to value2, then the block will be executed

--  #IfNotRow
--    arguments: table_name colname value
--    behavior:  If the table table_name does not have a row where colname = value, the block will be executed.

--  #IfNotRow2D
--    arguments: table_name colname value colname2 value2
--    behavior:  If the table table_name does not have a row where colname = value AND colname2 = value2, the block will be executed.

--  #IfNotRow3D
--    arguments: table_name colname value colname2 value2 colname3 value3
--    behavior:  If the table table_name does not have a row where colname = value AND colname2 = value2 AND colname3 = value3, the block will be executed.

--  #IfNotRow4D
--    arguments: table_name colname value colname2 value2 colname3 value3 colname4 value4
--    behavior:  If the table table_name does not have a row where colname = value AND colname2 = value2 AND colname3 = value3 AND colname4 = value4, the block will be executed.

--  #IfNotRow2Dx2
--    desc:      This is a very specialized function to allow adding items to the list_options table to avoid both redundant option_id and title in each element.
--    arguments: table_name colname value colname2 value2 colname3 value3
--    behavior:  The block will be executed if both statements below are true:
--               1) The table table_name does not have a row where colname = value AND colname2 = value2.
--               2) The table table_name does not have a row where colname = value AND colname3 = value3.

--  #IfRow
--    arguments: table_name colname value
--    behavior:  If the table table_name does have a row where colname = value, the block will be executed.

--  #IfRow2D
--    arguments: table_name colname value colname2 value2
--    behavior:  If the table table_name does have a row where colname = value AND colname2 = value2, the block will be executed.

--  #IfRow3D
--        arguments: table_name colname value colname2 value2 colname3 value3
--        behavior:  If the table table_name does have a row where colname = value AND colname2 = value2 AND colname3 = value3, the block will be executed.

--  #IfRowIsNull
--    arguments: table_name colname
--    behavior:  If the table table_name does have a row where colname is null, the block will be executed.

--  #IfIndex
--    desc:      This function is most often used for dropping of indexes/keys.
--    arguments: table_name colname
--    behavior:  If the table and index exist the relevant statements are executed, otherwise not.

--  #IfNotIndex
--    desc:      This function will allow adding of indexes/keys.
--    arguments: table_name colname
--    behavior:  If the index does not exist, it will be created

--  #EndIf
--    all blocks are terminated with a #EndIf statement.

--  #IfNotListReaction
--    Custom function for creating Reaction List

--  #IfNotListOccupation
--    Custom function for creating Occupation List

--  #IfTextNullFixNeeded
--    desc: convert all text fields without default null to have default null.
--    arguments: none

--  #IfTableEngine
--    desc:      Execute SQL if the table has been created with given engine specified.
--    arguments: table_name engine
--    behavior:  Use when engine conversion requires more than one ALTER TABLE

--  #IfInnoDBMigrationNeeded
--    desc: find all MyISAM tables and convert them to InnoDB.
--    arguments: none
--    behavior: can take a long time.

--  #IfDocumentNamingNeeded
--    desc: populate name field with document names.
--    arguments: none

--  #IfUpdateEditOptionsNeeded
--    desc: Change Layout edit options.
--    arguments: mode(add or remove) layout_form_id the_edit_option comma_separated_list_of_field_ids

--  #IfVitalsDatesNeeded
--    desc: Change date from zeroes to date of vitals form creation.
--    arguments: none

--  #IfMBOEncounterNeeded
--    desc: Add encounter to the form_misc_billing_options table
--    arguments: none

SET @rename_clinical_document_type_mappings := IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'agentforge_document_type_mappings') = 1
  AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'clinical_document_type_mappings') = 0,
  'RENAME TABLE `agentforge_document_type_mappings` TO `clinical_document_type_mappings`',
  'SELECT 1'
);
PREPARE rename_clinical_document_type_mappings_stmt FROM @rename_clinical_document_type_mappings;
EXECUTE rename_clinical_document_type_mappings_stmt;
DEALLOCATE PREPARE rename_clinical_document_type_mappings_stmt;

SET @rename_clinical_document_processing_jobs := IF(
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'agentforge_document_jobs') = 1
  AND (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'clinical_document_processing_jobs') = 0,
  'RENAME TABLE `agentforge_document_jobs` TO `clinical_document_processing_jobs`',
  'SELECT 1'
);
PREPARE rename_clinical_document_processing_jobs_stmt FROM @rename_clinical_document_processing_jobs;
EXECUTE rename_clinical_document_processing_jobs_stmt;
DEALLOCATE PREPARE rename_clinical_document_processing_jobs_stmt;

#IfNotTable clinical_document_type_mappings
CREATE TABLE `clinical_document_type_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `doc_type` varchar(32) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clinical_document_type_mapping` (`category_id`, `doc_type`),
  KEY `idx_clinical_document_type_active` (`active`, `category_id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable clinical_document_processing_jobs
CREATE TABLE `clinical_document_processing_jobs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `doc_type` varchar(32) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `lock_token` varchar(64) NULL,
  `created_at` datetime NOT NULL,
  `started_at` datetime NULL,
  `finished_at` datetime NULL,
  `error_code` varchar(64) NULL,
  `error_message` text NULL,
  `retracted_at` datetime NULL,
  `retraction_reason` varchar(64) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clinical_document_processing_job` (`patient_id`, `document_id`, `doc_type`),
  KEY `idx_clinical_document_processing_status_created` (`status`, `created_at`)
) ENGINE=InnoDB;
#EndIf

SET @rename_clinical_document_type_mapping_unique := IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'clinical_document_type_mappings' AND index_name = 'uniq_agentforge_doctype_mapping') = 1
  AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'clinical_document_type_mappings' AND index_name = 'uniq_clinical_document_type_mapping') = 0,
  'ALTER TABLE `clinical_document_type_mappings` RENAME INDEX `uniq_agentforge_doctype_mapping` TO `uniq_clinical_document_type_mapping`',
  'SELECT 1'
);
PREPARE rename_clinical_document_type_mapping_unique_stmt FROM @rename_clinical_document_type_mapping_unique;
EXECUTE rename_clinical_document_type_mapping_unique_stmt;
DEALLOCATE PREPARE rename_clinical_document_type_mapping_unique_stmt;

SET @rename_clinical_document_type_mapping_active := IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'clinical_document_type_mappings' AND index_name = 'idx_agentforge_doctype_active') = 1
  AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'clinical_document_type_mappings' AND index_name = 'idx_clinical_document_type_active') = 0,
  'ALTER TABLE `clinical_document_type_mappings` RENAME INDEX `idx_agentforge_doctype_active` TO `idx_clinical_document_type_active`',
  'SELECT 1'
);
PREPARE rename_clinical_document_type_mapping_active_stmt FROM @rename_clinical_document_type_mapping_active;
EXECUTE rename_clinical_document_type_mapping_active_stmt;
DEALLOCATE PREPARE rename_clinical_document_type_mapping_active_stmt;

SET @rename_clinical_document_processing_unique := IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'clinical_document_processing_jobs' AND index_name = 'uniq_agentforge_job_doc') = 1
  AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'clinical_document_processing_jobs' AND index_name = 'uniq_clinical_document_processing_job') = 0,
  'ALTER TABLE `clinical_document_processing_jobs` RENAME INDEX `uniq_agentforge_job_doc` TO `uniq_clinical_document_processing_job`',
  'SELECT 1'
);
PREPARE rename_clinical_document_processing_unique_stmt FROM @rename_clinical_document_processing_unique;
EXECUTE rename_clinical_document_processing_unique_stmt;
DEALLOCATE PREPARE rename_clinical_document_processing_unique_stmt;

SET @rename_clinical_document_processing_status := IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'clinical_document_processing_jobs' AND index_name = 'idx_agentforge_job_status_created') = 1
  AND (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'clinical_document_processing_jobs' AND index_name = 'idx_clinical_document_processing_status_created') = 0,
  'ALTER TABLE `clinical_document_processing_jobs` RENAME INDEX `idx_agentforge_job_status_created` TO `idx_clinical_document_processing_status_created`',
  'SELECT 1'
);
PREPARE rename_clinical_document_processing_status_stmt FROM @rename_clinical_document_processing_status;
EXECUTE rename_clinical_document_processing_status_stmt;
DEALLOCATE PREPARE rename_clinical_document_processing_status_stmt;

#IfMissingColumn clinical_document_processing_jobs retracted_at
ALTER TABLE `clinical_document_processing_jobs` ADD COLUMN `retracted_at` datetime NULL;
#EndIf

#IfMissingColumn clinical_document_processing_jobs retraction_reason
ALTER TABLE `clinical_document_processing_jobs` ADD COLUMN `retraction_reason` varchar(64) NULL;
#EndIf
