-- =====================================================================
-- File 2 of 3: fictional sample data (all names and numbers are made up)
-- Run after schema.sql
-- =====================================================================
USE nrb_db;

INSERT INTO district (district_id, district_name, region) VALUES
 (1,'Blantyre','Southern'),
 (2,'Zomba','Southern'),
 (3,'Lilongwe','Central'),
 (4,'Mzimba','Northern'),
 (5,'Mangochi','Southern');

INSERT INTO traditional_authority (ta_id, ta_name, chief_name, chief_title, district_id) VALUES
 (1,'Kapeni','Kapeni III','Senior Chief',1),
 (2,'Machinjiri','Machinjiri II','Traditional Authority',1),
 (3,'Mwambo','Mwambo IV','Traditional Authority',2),
 (4,'Kalolo','Kalolo V','Traditional Authority',3),
 (5,'Mtwalo','Mtwalo II','Inkosi',4),
 (6,'Jalasi','Jalasi','Senior Chief',5);

INSERT INTO address (address_id, village, physical_description, postal_box, ta_id) VALUES
 (1,'Chilomoni','Near the market, Blantyre','P.O. Box 120, Blantyre',1),
 (2,'Chirimba','Behind the primary school','P.O. Box 45, Blantyre',2),
 (3,'Chinamwali','Along the Zomba-Jali road',NULL,3),
 (4,'Area 25','House 25/112, Lilongwe','P.O. Box 301, Lilongwe',4),
 (5,'Ekwendeni','Close to the hospital','P.O. Box 8, Ekwendeni',5),
 (6,'Monkey Bay','Lakeshore, near the jetty',NULL,6),
 (7,'Ndirande','Ndirande Township, Blantyre','P.O. Box 77, Blantyre',1);

INSERT INTO person (person_id, nid_number, first_name, middle_name, surname, maiden_name, date_of_birth, gender, place_of_birth, marital_status, life_status, date_of_death) VALUES
 (1,'MW4KD92LA','Chisomo','Grace','Banda',NULL,'1998-03-14','F','Blantyre','Single','Alive',NULL),
 (2,'MW7TQ31ZB','Thokozani','Peter','Phiri',NULL,'1990-07-02','M','Lilongwe','Married','Alive',NULL),
 (3,'MW2NB58XC','Mercy','Tadala','Mwale','Chirwa','1985-11-23','F','Mzimba','Married','Alive',NULL),
 (4,NULL,'Yamikani','Joseph','Kachingwe',NULL,'2005-01-30','M','Zomba','Single','Alive',NULL),
 (5,NULL,'Limbani','Esther','Msiska',NULL,'2007-09-09','F','Mangochi','Single','Alive',NULL),
 (6,'MW9PL64QD','Wezi','Alinafe','Gondwe',NULL,'1975-05-18','M','Karonga','Married','Alive',NULL),
 (7,NULL,'Rajesh','Kumar','Patel',NULL,'1982-12-05','M','Mumbai','Married','Alive',NULL),
 (8,NULL,'Amara','Ngozi','Okafor',NULL,'1993-04-21','F','Lagos','Single','Alive',NULL),
 (9,NULL,'Madalitso','Dalitso','Chikapa',NULL,'1960-08-12','M','Chikwawa','Widowed','Deceased','2023-02-14');

INSERT INTO person_phone (person_id, phone_no) VALUES
 (1,'+265991234567'),
 (1,'+265881234567'),
 (2,'+265999876543'),
 (3,'+265888112233'),
 (4,'+265995554433'),
 (6,'+265991002003'),
 (7,'+265998889900'),
 (8,'+265884445566');

INSERT INTO citizen (person_id, citizenship_basis, citizenship_cert_no, acquired_on) VALUES
 (1,'Birth','CC-0001-1998','1998-03-14'),
 (2,'Birth','CC-0002-1990','1990-07-02'),
 (3,'Birth','CC-0003-1985','1985-11-23'),
 (4,'Birth',NULL,'2005-01-30'),
 (5,'Birth',NULL,'2007-09-09'),
 (6,'Descent','CC-0006-1975','1975-05-18'),
 (9,'Birth','CC-0009-1960','1960-08-12');

INSERT INTO foreign_resident (person_id, passport_no, country_of_origin, permit_no, permit_expiry) VALUES
 (7,'P4401827','India','GRP-2024-0156','2027-06-30'),
 (8,'A09977123','Nigeria','TEP-2025-0342','2026-12-31');

INSERT INTO biometric (person_id, biometric_code, biometric_type, template_data, quality_score, captured_on) VALUES
 (1,'FP-0001-R1','Fingerprint',UNHEX('4E52425F46505F30303031'),92.50,'2022-03-15 09:30:00'),
 (1,'FACE-0001','Face photo',UNHEX('4E52425F464143455F30303031'),88.00,'2022-03-15 09:35:00'),
 (2,'FP-0002-R1','Fingerprint',UNHEX('4E52425F46505F30303032'),95.10,'2021-06-10 10:00:00'),
 (3,'SIG-0003','Signature',UNHEX('4E52425F5349475F30303033'),81.75,'2021-08-02 11:20:00');

INSERT INTO resides_at (person_id, address_id, from_date, to_date, address_type) VALUES
 (1,1,'2015-01-01',NULL,'Permanent'),
 (2,4,'2018-06-01',NULL,'Permanent'),
 (3,5,'2005-01-01','2019-12-31','Permanent'),
 (3,4,'2020-01-01',NULL,'Permanent'),
 (4,3,'2005-01-30',NULL,'Permanent'),
 (5,6,'2007-09-09',NULL,'Permanent'),
 (6,2,'2000-01-01',NULL,'Permanent'),
 (7,7,'2019-03-01',NULL,'Temporary'),
 (8,1,'2024-02-01',NULL,'Temporary');

INSERT INTO parent_of (parent_id, child_id, parent_type) VALUES
 (6,1,'Father'),
 (3,5,'Mother'),
 (2,4,'Guardian');

INSERT INTO registration_centre (centre_id, centre_name, centre_type, status, district_id) VALUES
 (1,'Blantyre District Registration Office','District','Active',1),
 (2,'Zomba Registration Centre','District','Active',2),
 (3,'Lilongwe City Registration Centre','City','Active',3),
 (4,'Mzimba Mobile Registration Point','Mobile','Inactive',4);

INSERT INTO officer (officer_id, employee_no, first_name, surname, job_role, centre_id) VALUES
 (1,'NRB-0101','Grace','Kamanga','Registration Officer',1),
 (2,'NRB-0102','Patrick','Nyirenda','Registration Supervisor',1),
 (3,'NRB-0201','Foster','Mbewe','Registration Officer',2),
 (4,'NRB-0301','Esnart','Kaunda','Registration Officer',3);

INSERT INTO application (application_id, form_no, receipt_no, application_type, application_date, late_registration, status, rejection_reason, person_id, centre_id, captured_by, approved_by, approval_date) VALUES
 (1,'NR1','RCP-2022-0001','New','2022-03-15',0,'Approved',NULL,1,1,1,2,'2022-03-20'),
 (2,'NR1','RCP-2021-0420','New','2021-06-10',0,'Approved',NULL,2,3,4,4,'2021-06-18'),
 (3,'NR1','RCP-2021-0533','New','2021-08-02',0,'Approved',NULL,3,3,4,4,'2021-08-09'),
 (4,'NR1','RCP-2026-0710','New','2026-09-02',0,'Pending',NULL,4,2,3,NULL,NULL),
 (5,'NR1','RCP-2026-0755','New','2026-09-15',1,'Pending',NULL,5,2,3,NULL,NULL),
 (6,'NR1','RCP-2020-0118','New','2020-02-11',0,'Approved',NULL,6,1,1,2,'2020-02-19'),
 (7,'NR2','RCP-2026-0801','New','2026-10-01',0,'Rejected','Permit documents could not be verified',7,1,1,NULL,NULL),
 (8,'NR2','RCP-2026-0802','New','2026-10-05',0,'Pending',NULL,8,1,2,NULL,NULL);

INSERT INTO supporting_document (application_id, doc_seg, doc_type, doc_number, issuing_authority, verified) VALUES
 (1,1,'Birth certificate','BC-1998-4421','Blantyre District Registrar',1),
 (2,1,'Birth certificate','BC-1990-0912','Lilongwe District Registrar',1),
 (4,1,'Birth certificate','BC-2005-0077','Zomba District Registrar',1),
 (4,2,'School certificate','MSCE-2023-9981','Malawi National Examinations Board',0),
 (7,1,'Temporary employment permit','GRP-2024-0156','Department of Immigration',0),
 (8,1,'Passport','A09977123','Nigerian Immigration Service',1);

INSERT INTO witness (witness_id, witness_type, person_id, ta_id) VALUES
 (1,'Person',6,NULL),
 (2,'TA',NULL,3),
 (3,'TA',NULL,6);

INSERT INTO witness_application (witness_id, application_id, witness_date) VALUES
 (2,4,'2026-09-02'),
 (3,5,'2026-09-15'),
 (1,5,'2026-09-15');

INSERT INTO id_card (card_id, card_serial_no, card_type, issue_date, expiry_date, card_status, collection_date, application_id, person_id) VALUES
 (1,'SN-2022-000145','Citizen','2022-04-05','2032-04-05','Active','2022-04-12',1,1),
 (2,'SN-2021-000987','Citizen','2021-07-01','2031-07-01','Active','2021-07-09',2,2),
 (3,'SN-2021-001204','Citizen','2021-08-25','2031-08-25','Active',NULL,3,3),
 (4,'SN-2020-000311','Citizen','2020-03-01','2030-03-01','Lost','2020-03-10',6,6);

INSERT INTO institution (institution_id, institution_name, sector, agreement_ref, agreement_status) VALUES
 (1,'Sunrise Community Bank','Banking','AGR-2023-001','Active'),
 (2,'Mulanje Mobile Network','Telecommunications','AGR-2024-014','Active'),
 (3,'Lakeview Microfinance','Microfinance','AGR-2022-007','Suspended');

INSERT INTO verification_log (log_id, request_datetime, method, outcome, institution_id, person_id) VALUES
 (1,'2026-09-10 10:15:00','NID number lookup','Match',1,1),
 (2,'2026-09-12 14:02:30','Fingerprint','Match',2,2),
 (3,'2026-09-20 09:45:10','NID number lookup','No match',1,3),
 (4,'2026-10-02 16:30:00','NID number lookup','Match',1,1);
