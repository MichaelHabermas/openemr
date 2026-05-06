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

#IfNotTable clinical_document_type_mappings
CREATE TABLE `clinical_document_type_mappings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `doc_type` varchar(32) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clinical_document_type_mapping` (`category_id`),
  KEY `idx_clinical_document_type_active` (`active`, `category_id`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable clinical_document_processing_jobs
CREATE TABLE `clinical_document_processing_jobs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) NOT NULL,
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

#IfNotTable clinical_document_identity_checks
CREATE TABLE `clinical_document_identity_checks` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `job_id` bigint(20) NOT NULL,
  `doc_type` varchar(32) NOT NULL,
  `identity_status` varchar(64) NOT NULL DEFAULT 'identity_unchecked',
  `extracted_identifiers_json` longtext NULL,
  `matched_patient_fields_json` longtext NULL,
  `mismatch_reason` text NULL,
  `review_required` tinyint(1) NOT NULL DEFAULT 0,
  `review_decision` varchar(64) NULL,
  `reviewed_by` bigint(20) NULL,
  `reviewed_at` datetime NULL,
  `checked_at` datetime NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clinical_document_identity_check_job` (`job_id`),
  KEY `idx_clinical_document_identity_patient_document` (`patient_id`, `document_id`),
  KEY `idx_clinical_document_identity_status` (`identity_status`, `review_required`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable clinical_document_promoted_facts
CREATE TABLE `clinical_document_promoted_facts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `job_id` bigint(20) NOT NULL,
  `patient_id` bigint(20) NOT NULL,
  `document_id` int(11) NOT NULL,
  `doc_type` varchar(32) NOT NULL,
  `fact_type` varchar(32) NOT NULL,
  `field_path` varchar(255) NOT NULL,
  `display_label` varchar(255) NOT NULL,
  `value_json` longtext NOT NULL,
  `citation_json` longtext NOT NULL,
  `bounding_box_json` longtext NULL,
  `fact_hash` char(64) NOT NULL,
  `promotion_status` varchar(32) NOT NULL DEFAULT 'needs_review',
  `native_table` varchar(64) NULL,
  `native_id` varchar(64) NULL,
  `review_status` varchar(32) NOT NULL DEFAULT 'needs_review',
  `reviewed_by` bigint(20) NULL,
  `reviewed_at` datetime NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clinical_document_promoted_fact` (`job_id`, `fact_hash`),
  KEY `idx_clinical_document_promoted_patient_hash` (`patient_id`, `fact_hash`),
  KEY `idx_clinical_document_promoted_patient_document` (`patient_id`, `document_id`),
  KEY `idx_clinical_document_promoted_status` (`promotion_status`, `review_status`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable clinical_document_promotions
CREATE TABLE `clinical_document_promotions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) NOT NULL,
  `document_id` int(11) NOT NULL,
  `job_id` bigint(20) NOT NULL,
  `fact_id` varchar(255) NOT NULL,
  `doc_type` varchar(32) NOT NULL,
  `fact_type` varchar(32) NOT NULL,
  `field_path` varchar(255) NOT NULL,
  `display_label` varchar(255) NOT NULL,
  `value_json` longtext NOT NULL,
  `fact_fingerprint` char(64) NOT NULL,
  `clinical_content_fingerprint` char(64) NOT NULL,
  `promoted_table` varchar(64) NOT NULL DEFAULT '',
  `promoted_record_id` varchar(64) NULL,
  `promoted_pk_json` longtext NULL,
  `outcome` varchar(32) NOT NULL,
  `duplicate_key` varchar(255) NULL,
  `conflict_reason` text NULL,
  `citation_json` longtext NOT NULL,
  `bounding_box_json` longtext NULL,
  `confidence` decimal(5,4) NULL,
  `review_status` varchar(32) NOT NULL DEFAULT 'needs_review',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `retracted_at` datetime NULL,
  `retraction_reason` varchar(64) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clinical_document_promotion_target` (`patient_id`, `fact_id`, `promoted_table`),
  UNIQUE KEY `uniq_clinical_document_promotion_source` (`job_id`, `fact_fingerprint`),
  KEY `idx_clinical_document_promotion_content` (`patient_id`, `clinical_content_fingerprint`, `active`),
  KEY `idx_clinical_document_promotion_document` (`patient_id`, `document_id`),
  KEY `idx_clinical_document_promotion_outcome` (`outcome`, `review_status`, `active`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable clinical_document_facts
CREATE TABLE `clinical_document_facts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `patient_id` bigint(20) NOT NULL,
  `document_id` int(11) NOT NULL,
  `job_id` bigint(20) NOT NULL,
  `identity_check_id` bigint(20) NULL,
  `doc_type` varchar(32) NOT NULL,
  `fact_type` varchar(32) NOT NULL,
  `certainty` varchar(32) NOT NULL,
  `fact_fingerprint` char(64) NOT NULL,
  `clinical_content_fingerprint` char(64) NOT NULL,
  `fact_text` text NOT NULL,
  `structured_value_json` longtext NOT NULL,
  `citation_json` longtext NOT NULL,
  `confidence` decimal(5,4) NULL,
  `promotion_status` varchar(32) NOT NULL DEFAULT 'not_promoted',
  `retracted_at` datetime NULL,
  `retraction_reason` varchar(64) NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `deactivated_at` datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clinical_document_fact_source` (`patient_id`, `document_id`, `doc_type`, `fact_fingerprint`),
  KEY `idx_clinical_document_fact_patient_active` (`patient_id`, `active`, `retracted_at`, `created_at`),
  KEY `idx_clinical_document_fact_content` (`patient_id`, `clinical_content_fingerprint`, `active`),
  KEY `idx_clinical_document_fact_job` (`job_id`, `active`),
  KEY `idx_clinical_document_fact_promotion` (`promotion_status`, `active`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable clinical_document_fact_embeddings
CREATE TABLE `clinical_document_fact_embeddings` (
  `fact_id` bigint(20) NOT NULL,
  `embedding` VECTOR(1536) NOT NULL,
  `embedding_model` varchar(128) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`fact_id`, `embedding_model`),
  KEY `idx_clinical_document_fact_embeddings_active` (`active`, `embedding_model`)
) ENGINE=InnoDB;
#EndIf

INSERT IGNORE INTO `clinical_document_promotions` (
  `patient_id`,
  `document_id`,
  `job_id`,
  `fact_id`,
  `doc_type`,
  `fact_type`,
  `field_path`,
  `display_label`,
  `value_json`,
  `fact_fingerprint`,
  `clinical_content_fingerprint`,
  `promoted_table`,
  `promoted_record_id`,
  `promoted_pk_json`,
  `outcome`,
  `duplicate_key`,
  `citation_json`,
  `bounding_box_json`,
  `review_status`,
  `active`,
  `created_at`,
  `updated_at`
)
SELECT
  `patient_id`,
  `document_id`,
  `job_id`,
  `fact_hash`,
  `doc_type`,
  `fact_type`,
  `field_path`,
  `display_label`,
  `value_json`,
  `fact_hash`,
  `fact_hash`,
  COALESCE(`native_table`, ''),
  `native_id`,
  CASE WHEN `native_id` IS NULL THEN NULL ELSE JSON_OBJECT('legacy_native_id', `native_id`) END,
  CASE `promotion_status`
    WHEN 'skipped_duplicate' THEN 'duplicate_skipped'
    WHEN 'superseded' THEN 'retracted'
    ELSE `promotion_status`
  END,
  `fact_hash`,
  `citation_json`,
  `bounding_box_json`,
  `review_status`,
  CASE WHEN `promotion_status` = 'superseded' THEN 0 ELSE 1 END,
  `created_at`,
  `updated_at`
FROM `clinical_document_promoted_facts`;

#IfMissingColumn clinical_document_processing_jobs retracted_at
ALTER TABLE `clinical_document_processing_jobs` ADD COLUMN `retracted_at` datetime NULL;
#EndIf

#IfMissingColumn clinical_document_processing_jobs retraction_reason
ALTER TABLE `clinical_document_processing_jobs` ADD COLUMN `retraction_reason` varchar(64) NULL;
#EndIf

#IfNotTable clinical_document_worker_heartbeats
CREATE TABLE `clinical_document_worker_heartbeats` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `worker` varchar(64) NOT NULL,
  `process_id` int(11) NOT NULL,
  `status` varchar(32) NOT NULL,
  `iteration_count` bigint(20) NOT NULL DEFAULT 0,
  `jobs_processed` bigint(20) NOT NULL DEFAULT 0,
  `jobs_failed` bigint(20) NOT NULL DEFAULT 0,
  `started_at` datetime NOT NULL,
  `last_heartbeat_at` datetime NOT NULL,
  `stopped_at` datetime NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clinical_document_worker_heartbeats_worker` (`worker`),
  KEY `idx_clinical_document_worker_heartbeats_status` (`status`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable clinical_supervisor_handoffs
CREATE TABLE `clinical_supervisor_handoffs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` varchar(64) NULL,
  `job_id` bigint(20) NULL,
  `source_node` varchar(64) NOT NULL,
  `destination_node` varchar(64) NOT NULL,
  `decision_reason` varchar(128) NOT NULL,
  `task_type` varchar(64) NOT NULL,
  `outcome` varchar(64) NOT NULL,
  `latency_ms` int(11) NULL,
  `error_reason` varchar(128) NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_clinical_supervisor_handoff_request` (`request_id`, `created_at`),
  KEY `idx_clinical_supervisor_handoff_job` (`job_id`, `created_at`),
  KEY `idx_clinical_supervisor_handoff_destination` (`destination_node`, `created_at`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable clinical_guideline_chunks
CREATE TABLE `clinical_guideline_chunks` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `chunk_id` varchar(191) NOT NULL,
  `corpus_version` varchar(191) NOT NULL,
  `source_title` varchar(255) NOT NULL,
  `source_url_or_file` varchar(255) NOT NULL,
  `section` varchar(255) NOT NULL,
  `chunk_text` text NOT NULL,
  `citation_json` longtext NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_clinical_guideline_chunk_version` (`corpus_version`, `chunk_id`),
  KEY `idx_clinical_guideline_chunks_active_version` (`active`, `corpus_version`)
) ENGINE=InnoDB;
#EndIf

#IfNotTable clinical_guideline_chunk_embeddings
CREATE TABLE `clinical_guideline_chunk_embeddings` (
  `chunk_id` varchar(191) NOT NULL,
  `corpus_version` varchar(191) NOT NULL,
  `embedding` VECTOR(1536) NOT NULL,
  `embedding_model` varchar(128) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`corpus_version`, `chunk_id`),
  KEY `idx_clinical_guideline_embeddings_active` (`active`, `corpus_version`)
) ENGINE=InnoDB;
#EndIf
