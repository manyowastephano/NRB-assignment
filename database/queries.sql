-- =====================================================================
-- File 3 of 3: demonstration queries (retrieve, search, JOIN, UPDATE, DELETE)
-- Run after schema.sql and sample_data.sql
-- =====================================================================
USE nrb_db;

-- ---------------------------------------------------------------------
-- 1. Retrieve applicant records
-- ---------------------------------------------------------------------

-- 1.1 All registered persons, newest registration first
SELECT person_id, nid_number, first_name, middle_name, surname,
       date_of_birth, gender, life_status
FROM person
ORDER BY person_id DESC;

-- 1.2 All applications with their status
SELECT application_id, receipt_no, application_type, application_date, status
FROM application
ORDER BY application_date DESC;

-- 1.3 Number of applications per status
SELECT status, COUNT(*) AS total
FROM application
GROUP BY status;

-- ---------------------------------------------------------------------
-- 2. Search for applicants using identifiers
-- ---------------------------------------------------------------------

-- 2.1 By NID number
SELECT person_id, first_name, surname, date_of_birth
FROM person
WHERE nid_number = 'MW4KD92LA';

-- 2.2 By receipt number
SELECT p.person_id, p.first_name, p.surname, a.receipt_no, a.status
FROM application a
JOIN person p ON p.person_id = a.person_id
WHERE a.receipt_no = 'RCP-2026-0710';

-- 2.3 By part of a name (case-insensitive)
SELECT person_id, first_name, surname, date_of_birth
FROM person
WHERE first_name LIKE '%an%' OR surname LIKE '%an%';

-- 2.4 By phone number
SELECT p.person_id, p.first_name, p.surname, ph.phone_no
FROM person_phone ph
JOIN person p ON p.person_id = ph.person_id
WHERE ph.phone_no = '+265991234567';

-- ---------------------------------------------------------------------
-- 3. Related information using JOIN operations
-- ---------------------------------------------------------------------

-- 3.1 Applicant, application, registration centre and district
SELECT p.first_name, p.surname, a.receipt_no, a.status,
       rc.centre_name, d.district_name
FROM application a
JOIN person p               ON p.person_id  = a.person_id
JOIN registration_centre rc ON rc.centre_id = a.centre_id
JOIN district d             ON d.district_id = rc.district_id
ORDER BY a.application_id;

-- 3.2 Capturing and approving officers (approving officer may be NULL, so LEFT JOIN)
SELECT a.receipt_no,
       CONCAT(c.first_name, ' ', c.surname) AS captured_by,
       CONCAT(ap.first_name, ' ', ap.surname) AS approved_by,
       a.approval_date
FROM application a
JOIN officer c       ON c.officer_id  = a.captured_by
LEFT JOIN officer ap ON ap.officer_id = a.approved_by;

-- 3.3 Current address of each person: village, TA and district
SELECT p.first_name, p.surname, ad.village, ta.ta_name, d.district_name
FROM resides_at r
JOIN person p                ON p.person_id    = r.person_id
JOIN address ad              ON ad.address_id  = r.address_id
JOIN traditional_authority ta ON ta.ta_id      = ad.ta_id
JOIN district d              ON d.district_id  = ta.district_id
WHERE r.to_date IS NULL;

-- 3.4 Citizens and foreign residents with their specific details
SELECT p.first_name, p.surname, 'Citizen' AS category,
       c.citizenship_basis AS detail_1, c.citizenship_cert_no AS detail_2
FROM person p JOIN citizen c ON c.person_id = p.person_id
UNION ALL
SELECT p.first_name, p.surname, 'Foreign resident',
       f.country_of_origin, f.permit_no
FROM person p JOIN foreign_resident f ON f.person_id = p.person_id;

-- 3.5 ID cards with holder, application receipt and status
SELECT c.card_serial_no, p.first_name, p.surname, p.nid_number,
       a.receipt_no, c.issue_date, c.expiry_date, c.card_status
FROM id_card c
JOIN person p      ON p.person_id = c.person_id
JOIN application a ON a.application_id = c.application_id;

-- 3.6 Approved applications that do not yet have a card
SELECT a.application_id, a.receipt_no, p.first_name, p.surname
FROM application a
JOIN person p ON p.person_id = a.person_id
LEFT JOIN id_card c ON c.application_id = a.application_id
WHERE a.status = 'Approved' AND c.card_id IS NULL;

-- 3.7 Supporting documents and witnesses of an application
SELECT a.receipt_no, sd.doc_type, sd.doc_number, sd.verified
FROM supporting_document sd
JOIN application a ON a.application_id = sd.application_id
WHERE a.application_id = 4;

SELECT a.receipt_no, w.witness_type,
       COALESCE(CONCAT(p.first_name, ' ', p.surname), ta.ta_name) AS witness_name,
       wa.witness_date
FROM witness_application wa
JOIN witness w ON w.witness_id = wa.witness_id
JOIN application a ON a.application_id = wa.application_id
LEFT JOIN person p ON p.person_id = w.person_id
LEFT JOIN traditional_authority ta ON ta.ta_id = w.ta_id
WHERE a.application_id = 5;

-- 3.8 Verification requests made by institutions
SELECT i.institution_name, p.first_name, p.surname, v.request_datetime, v.method, v.outcome
FROM verification_log v
JOIN institution i ON i.institution_id = v.institution_id
JOIN person p      ON p.person_id = v.person_id
ORDER BY v.request_datetime DESC;

-- 3.9 Parents and children
SELECT CONCAT(par.first_name, ' ', par.surname) AS parent,
       po.parent_type,
       CONCAT(ch.first_name, ' ', ch.surname) AS child
FROM parent_of po
JOIN person par ON par.person_id = po.parent_id
JOIN person ch  ON ch.person_id  = po.child_id;

-- ---------------------------------------------------------------------
-- 4. Data modification (UPDATE)
-- ---------------------------------------------------------------------

-- 4.1 Correct a marital status
UPDATE person SET marital_status = 'Married' WHERE person_id = 1;

-- 4.2 Approve a pending application (approved_by and approval_date are required together)
UPDATE application
SET status = 'Approved', approved_by = 3, approval_date = '2026-10-10'
WHERE application_id = 4;

-- 4.3 Record that a card has been collected
UPDATE id_card SET collection_date = '2026-10-12' WHERE card_id = 3;

-- 4.4 Mark an old card as replaced
UPDATE id_card SET card_status = 'Replaced' WHERE card_id = 4;

-- Check the changes
SELECT application_id, status, approved_by, approval_date FROM application WHERE application_id = 4;

-- ---------------------------------------------------------------------
-- 5. Deletion (DELETE)
-- ---------------------------------------------------------------------

-- 5.1 Delete a supporting document that was entered wrongly
DELETE FROM supporting_document WHERE application_id = 4 AND doc_seg = 2;

-- 5.2 Delete a person with no applications (the ON DELETE CASCADE rules also
--     remove the matching rows in citizen, person_phone and resides_at)
DELETE FROM person WHERE person_id = 9;

-- 5.3 Delete a rejected application together with its documents
--     (documents are removed by ON DELETE CASCADE)
DELETE FROM application WHERE application_id = 7 AND status = 'Rejected';

-- ---------------------------------------------------------------------
-- 6. Constraint tests (each statement below is expected to FAIL)
-- ---------------------------------------------------------------------
-- 6.1 Duplicate receipt number          -> ERROR 1062 (UNIQUE)
-- INSERT INTO application (form_no, receipt_no, application_type, application_date, person_id, centre_id, captured_by)
-- VALUES ('NR1','RCP-2022-0001','New','2026-10-20',4,1,1);

-- 6.2 Invalid gender                     -> ERROR 4025 / 3819 (CHECK)
-- UPDATE person SET gender = 'X' WHERE person_id = 1;

-- 6.3 Deleting a person who has a card   -> ERROR 1451 (FOREIGN KEY, RESTRICT)
-- DELETE FROM person WHERE person_id = 1;

-- 6.4 Approved without an approving officer -> CHECK violation
-- UPDATE application SET status = 'Approved' WHERE application_id = 5;

-- 6.5 Card that expires before it is issued  -> CHECK violation
-- UPDATE id_card SET expiry_date = '2020-01-01' WHERE card_id = 1;
