-- AgentForge Epic 3 demo data.
-- Idempotent for fake patient pid=900001. This script never drops tables.

SET @demo_pid := 900001;
SET @demo_pubpid := 'AF-DEMO-900001';
SET @demo_user := 'admin';
SET @demo_group := 'Default';
SET @encounter_id := 900415;

START TRANSACTION;

DELETE pr
FROM procedure_result pr
INNER JOIN procedure_report rep ON rep.procedure_report_id = pr.procedure_report_id
INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id
WHERE po.patient_id = @demo_pid;

DELETE rep
FROM procedure_report rep
INNER JOIN procedure_order po ON po.procedure_order_id = rep.procedure_order_id
WHERE po.patient_id = @demo_pid;

DELETE poc
FROM procedure_order_code poc
INNER JOIN procedure_order po ON po.procedure_order_id = poc.procedure_order_id
WHERE po.patient_id = @demo_pid;

DELETE FROM procedure_order WHERE patient_id = @demo_pid;
DELETE FROM forms WHERE pid = @demo_pid;
DELETE FROM form_clinical_notes WHERE pid = @demo_pid;
DELETE FROM form_encounter WHERE pid = @demo_pid;
DELETE FROM prescriptions WHERE patient_id = @demo_pid;
DELETE FROM lists WHERE pid = @demo_pid;
DELETE FROM patient_data WHERE pid = @demo_pid OR pubpid = @demo_pubpid;

INSERT INTO patient_data (
    id,
    uuid,
    fname,
    lname,
    DOB,
    sex,
    date,
    pubpid,
    pid,
    providerID,
    status,
    street,
    city,
    state,
    postal_code,
    phone_cell,
    email
) VALUES (
    @demo_pid,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000001', '-', '')),
    'Alex',
    'Testpatient',
    '1976-04-12',
    'Female',
    '2026-04-15 08:00:00',
    @demo_pubpid,
    @demo_pid,
    1,
    'active',
    '100 Demo Street',
    'Faketown',
    'NY',
    '10001',
    '555-0100',
    'alex.testpatient@example.invalid'
);

INSERT INTO lists (
    uuid,
    date,
    type,
    title,
    begdate,
    diagnosis,
    activity,
    comments,
    pid,
    user,
    groupname,
    external_id
) VALUES
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000101', '-', '')),
    '2025-09-10 09:00:00',
    'medical_problem',
    'Type 2 diabetes mellitus',
    '2025-09-10 00:00:00',
    'ICD10:E11.9',
    1,
    'AgentForge demo active problem.',
    @demo_pid,
    @demo_user,
    @demo_group,
    'af-prob-diabetes'
),
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000102', '-', '')),
    '2024-02-18 09:00:00',
    'medical_problem',
    'Essential hypertension',
    '2024-02-18 00:00:00',
    'ICD10:I10',
    1,
    'AgentForge demo active problem.',
    @demo_pid,
    @demo_user,
    @demo_group,
    'af-prob-htn'
);

INSERT INTO prescriptions (
    uuid,
    patient_id,
    filled_by_id,
    date_added,
    date_modified,
    provider_id,
    encounter,
    start_date,
    drug,
    dosage,
    quantity,
    route,
    refills,
    medication,
    note,
    active,
    datetime,
    user,
    txDate,
    usage_category_title,
    request_intent_title,
    drug_dosage_instructions,
    external_id
) VALUES
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000201', '-', '')),
    @demo_pid,
    1,
    '2026-03-15 10:00:00',
    '2026-03-15 10:00:00',
    1,
    @encounter_id,
    '2026-03-15',
    'Metformin ER 500 mg',
    '500 mg',
    '30',
    'oral',
    2,
    1,
    'AgentForge demo active medication.',
    1,
    '2026-03-15 10:00:00',
    @demo_user,
    '2026-03-15',
    'Community',
    'Order',
    'Take 1 tablet by mouth daily with evening meal',
    'af-rx-metformin'
),
(
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000202', '-', '')),
    @demo_pid,
    1,
    '2026-03-15 10:05:00',
    '2026-03-15 10:05:00',
    1,
    @encounter_id,
    '2026-03-15',
    'Lisinopril 10 mg',
    '10 mg',
    '30',
    'oral',
    2,
    1,
    'AgentForge demo active medication.',
    1,
    '2026-03-15 10:05:00',
    @demo_user,
    '2026-03-15',
    'Community',
    'Order',
    'Take 1 tablet by mouth daily',
    'af-rx-lisinopril'
);

INSERT INTO form_encounter (
    uuid,
    date,
    reason,
    facility,
    facility_id,
    pid,
    encounter,
    pc_catid,
    provider_id,
    class_code,
    encounter_type_description
) VALUES (
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000301', '-', '')),
    '2026-04-15 09:30:00',
    'Follow-up for diabetes and blood pressure before a scheduled primary care visit.',
    'AgentForge Demo Clinic',
    3,
    @demo_pid,
    @encounter_id,
    5,
    1,
    'AMB',
    'Primary care follow-up'
);

INSERT INTO form_clinical_notes (
    uuid,
    form_id,
    date,
    pid,
    encounter,
    user,
    groupname,
    authorized,
    activity,
    code,
    codetext,
    description,
    external_id,
    clinical_notes_type,
    clinical_notes_category,
    note_related_to
) VALUES (
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000302', '-', '')),
    0,
    '2026-04-15',
    @demo_pid,
    CAST(@encounter_id AS CHAR),
    @demo_user,
    @demo_group,
    1,
    1,
    'AGENTFORGE_LAST_PLAN',
    'Last plan',
    'Continue metformin ER and lisinopril. Review home blood pressure log at next visit. Recheck A1c in 3 months.',
    'af-note-20260415',
    'Clinical Note',
    'Plan',
    'diabetes follow-up'
);

SET @clinical_note_id := LAST_INSERT_ID();

INSERT INTO forms (
    date,
    encounter,
    form_name,
    form_id,
    pid,
    user,
    groupname,
    authorized,
    deleted,
    formdir,
    provider_id
) VALUES (
    '2026-04-15 09:30:00',
    @encounter_id,
    'Clinical Notes',
    @clinical_note_id,
    @demo_pid,
    @demo_user,
    @demo_group,
    1,
    0,
    'clinical_notes',
    1
);

UPDATE form_clinical_notes
SET form_id = LAST_INSERT_ID()
WHERE id = @clinical_note_id;

INSERT INTO procedure_order (
    procedure_order_id,
    uuid,
    provider_id,
    patient_id,
    encounter_id,
    date_collected,
    date_ordered,
    order_priority,
    order_status,
    clinical_hx,
    procedure_order_type,
    order_intent
) VALUES
(
    90000101,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000401', '-', '')),
    1,
    @demo_pid,
    @encounter_id,
    '2026-01-09 08:00:00',
    '2026-01-09 08:00:00',
    'routine',
    'complete',
    'AgentForge demo A1c trend.',
    'laboratory_test',
    'order'
),
(
    90000102,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000402', '-', '')),
    1,
    @demo_pid,
    @encounter_id,
    '2026-04-10 08:00:00',
    '2026-04-10 08:00:00',
    'routine',
    'complete',
    'AgentForge demo A1c trend.',
    'laboratory_test',
    'order'
);

INSERT INTO procedure_order_code (
    procedure_order_id,
    procedure_order_seq,
    procedure_code,
    procedure_name,
    procedure_source,
    diagnoses,
    do_not_send
) VALUES
(
    90000101,
    1,
    '4548-4',
    'Hemoglobin A1c',
    '1',
    'ICD10:E11.9',
    0
),
(
    90000102,
    1,
    '4548-4',
    'Hemoglobin A1c',
    '1',
    'ICD10:E11.9',
    0
);

INSERT INTO procedure_report (
    procedure_report_id,
    uuid,
    procedure_order_id,
    procedure_order_seq,
    date_collected,
    date_report,
    source,
    specimen_num,
    report_status,
    review_status,
    report_notes
) VALUES
(
    90000101,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000501', '-', '')),
    90000101,
    1,
    '2026-01-09 08:00:00',
    '2026-01-09 12:00:00',
    1,
    'AF-A1C-202601',
    'complete',
    'reviewed',
    'AgentForge demo lab report.'
),
(
    90000102,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000502', '-', '')),
    90000102,
    1,
    '2026-04-10 08:00:00',
    '2026-04-10 12:00:00',
    1,
    'AF-A1C-202604',
    'complete',
    'reviewed',
    'AgentForge demo lab report.'
);

INSERT INTO procedure_result (
    procedure_result_id,
    uuid,
    procedure_report_id,
    result_data_type,
    result_code,
    result_text,
    date,
    facility,
    units,
    result,
    `range`,
    abnormal,
    comments,
    result_status
) VALUES
(
    90000101,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000601', '-', '')),
    90000101,
    'N',
    '4548-4',
    'Hemoglobin A1c',
    '2026-01-09 12:00:00',
    'AgentForge Demo Lab',
    '%',
    '8.2',
    '4.0-5.6',
    'high',
    'agentforge-a1c-2026-01',
    'final'
),
(
    90000102,
    UNHEX(REPLACE('90000100-0000-4000-8000-000000000602', '-', '')),
    90000102,
    'N',
    '4548-4',
    'Hemoglobin A1c',
    '2026-04-10 12:00:00',
    'AgentForge Demo Lab',
    '%',
    '7.4',
    '4.0-5.6',
    'high',
    'agentforge-a1c-2026-04',
    'final'
);

COMMIT;
