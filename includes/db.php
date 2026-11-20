<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dir = dirname(DB_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (!is_dir(SCAN_DIR)) {
        mkdir(SCAN_DIR, 0775, true);
    }

    $fresh = !file_exists(DB_FILE);
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    if ($fresh) {
        db_install($pdo);
    }

    return $pdo;
}

function db_install(PDO $pdo): void
{
    $schema = <<<SQL
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    full_name     TEXT NOT NULL,
    role          TEXT NOT NULL DEFAULT 'staff',
    email         TEXT,
    is_active     INTEGER NOT NULL DEFAULT 1,
    last_login    TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE students (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    lrn             TEXT NOT NULL UNIQUE,
    first_name      TEXT NOT NULL,
    middle_name     TEXT,
    last_name       TEXT NOT NULL,
    sex             TEXT NOT NULL DEFAULT 'F',
    birth_date      TEXT,
    grade_level     TEXT NOT NULL,
    section         TEXT,
    strand          TEXT,
    school_year     TEXT NOT NULL,
    status          TEXT NOT NULL DEFAULT 'Enrolled',
    guardian_name   TEXT,
    guardian_contact TEXT,
    address         TEXT,
    previous_school TEXT,
    record_code     TEXT UNIQUE,
    cabinet         TEXT,
    shelf           TEXT,
    folder_no       TEXT,
    digital_path    TEXT,
    notes           TEXT,
    created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE credentials (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id    INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    doc_type      TEXT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'Missing',
    date_received TEXT,
    remarks       TEXT,
    updated_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (student_id, doc_type)
);

CREATE TABLE requests (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    request_no      TEXT NOT NULL UNIQUE,
    student_id      INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    document_type   TEXT NOT NULL,
    purpose         TEXT,
    requester_name  TEXT NOT NULL,
    relationship    TEXT,
    requester_contact TEXT,
    assigned_to     INTEGER REFERENCES users(id) ON DELETE SET NULL,
    status          TEXT NOT NULL DEFAULT 'Pending',
    date_requested  TEXT NOT NULL,
    date_needed     TEXT,
    date_released   TEXT,
    fee             REAL NOT NULL DEFAULT 0,
    remarks         TEXT,
    created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE followups (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id        INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    concern           TEXT NOT NULL,
    school_contacted  TEXT,
    contact_person    TEXT,
    method            TEXT NOT NULL DEFAULT 'Email',
    followup_date     TEXT NOT NULL,
    next_action       TEXT,
    next_followup_date TEXT,
    status            TEXT NOT NULL DEFAULT 'Open',
    created_by        INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at        TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE certifications (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    cert_no     TEXT NOT NULL UNIQUE,
    student_id  INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    cert_type   TEXT NOT NULL,
    issued_to   TEXT NOT NULL,
    purpose     TEXT,
    issued_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
    date_issued TEXT NOT NULL,
    remarks     TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE transfers (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id        INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
    direction         TEXT NOT NULL,
    counterpart_school TEXT NOT NULL,
    effective_date    TEXT,
    reason            TEXT,
    status            TEXT NOT NULL DEFAULT 'Documents Pending',
    notes             TEXT,
    created_by        INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at        TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE activity (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
    action     TEXT NOT NULL,
    details    TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE INDEX idx_students_lrn ON students(lrn);
CREATE INDEX idx_students_name ON students(last_name, first_name);
CREATE INDEX idx_requests_status ON requests(status);
CREATE INDEX idx_requests_student ON requests(student_id);
CREATE INDEX idx_credentials_student ON credentials(student_id);
SQL;

    $pdo->exec($schema);
    db_seed($pdo);
}

function db_seed(PDO $pdo): void
{
    $pdo->beginTransaction();

    $users = [
        ['mcamero', 'Mharry Xymhone Camero', 'admin', 'mcamero@regisplus.local'],
        ['etalha', 'Elaiza Jean Talha', 'registrar', 'etalha@regisplus.local'],
        ['xcordero', 'Xyrynn Klyd Urriel Cordero', 'staff', 'xcordero@regisplus.local'],
        ['malbacite', 'Mary Nathalie Albacite', 'staff', 'malbacite@regisplus.local'],
    ];
    $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, role, email) VALUES (?, ?, ?, ?, ?)');
    foreach ($users as [$u, $name, $role, $mail]) {
        $stmt->execute([$u, password_hash('regis2026', PASSWORD_DEFAULT), $name, $role, $mail]);
    }

    $settings = [
        'school_name' => 'Valencia City National High School',
        'school_address' => 'Valencia City, Bukidnon',
        'school_year' => '2026-2027',
        'registrar_name' => 'Elaiza Jean Talha',
        'registrar_title' => 'Registrar Officer',
        'principal_name' => 'Dr. Ramon A. Villamor',
        'principal_title' => 'School Principal IV',
        'backup_note' => 'Weekly database backup every Friday, 4:00 PM',
    ];
    $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)');
    foreach ($settings as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    $students = [
        ['Marisol', 'Abellana', 'D.', 'F', '2009-03-14', 'Grade 11', 'Sampaguita', 'STEM', 'Enrolled', 'Bukidnon National High School', 'Lorna Abellana', '0917 214 8890', 'Poblacion, Valencia City, Bukidnon'],
        ['Jomar', 'Bacalso', 'P.', 'M', '2010-07-02', 'Grade 10', 'Rizal', '', 'Enrolled', 'Valencia City National High School', 'Efren Bacalso', '0918 552 7741', 'Bagontaas, Valencia City, Bukidnon'],
        ['Kryzha Mae', 'Cabanillas', 'T.', 'F', '2008-11-21', 'Grade 12', 'Del Pilar', 'HUMSS', 'Enrolled', 'San Isidro National High School', 'Nenita Cabanillas', '0906 118 2245', 'Lurugan, Valencia City, Bukidnon'],
        ['Rodel', 'Dagohoy', 'V.', 'M', '2011-01-09', 'Grade 9', 'Mabini', '', 'Enrolled', 'Valencia City National High School', 'Ariel Dagohoy', '0995 330 1187', 'Mailag, Valencia City, Bukidnon'],
        ['Precious Anne', 'Emborong', 'L.', 'F', '2012-05-27', 'Grade 8', 'Bonifacio', '', 'Enrolled', 'Bagontaas National High School', 'Mercy Emborong', '0977 264 0018', 'Tongantongan, Valencia City, Bukidnon'],
        ['Christian Jay', 'Fabroa', 'M.', 'M', '2013-02-18', 'Grade 7', 'Luna', '', 'Enrolled', 'Valencia City National High School', 'Joselito Fabroa', '0916 445 7712', 'Poblacion, Valencia City, Bukidnon'],
        ['Liezl', 'Gacusan', 'B.', 'F', '2009-09-30', 'Grade 11', 'Sampaguita', 'ABM', 'Enrolled', 'Bukidnon National High School', 'Teresita Gacusan', '0939 887 5520', 'Sinayawan, Valencia City, Bukidnon'],
        ['Arnel', 'Hinautan', 'S.', 'M', '2008-06-12', 'Grade 12', 'Rizal', 'STEM', 'Transferred Out', 'Valencia City National High School', 'Dolores Hinautan', '0917 909 3341', 'Lumbayao, Valencia City, Bukidnon'],
        ['Jessa Mae', 'Isidro', 'C.', 'F', '2010-12-05', 'Grade 10', 'Del Pilar', '', 'Enrolled', 'San Isidro National High School', 'Rogelio Isidro', '0961 224 8890', 'Valencia City, Bukidnon'],
        ['Kevin Paul', 'Jalalon', 'R.', 'M', '2011-08-19', 'Grade 9', 'Mabini', '', 'Enrolled', 'Valencia City National High School', 'Myrna Jalalon', '0927 651 0034', 'Afga, Valencia City, Bukidnon'],
        ['Analyn', 'Kadusale', 'D.', 'F', '2012-10-08', 'Grade 8', 'Bonifacio', '', 'Enrolled', 'Lurugan National High School', 'Bert Kadusale', '0918 774 2290', 'Lurugan, Valencia City, Bukidnon'],
        ['Mark Anthony', 'Lagumbay', 'B.', 'M', '2013-04-23', 'Grade 7', 'Luna', '', 'Enrolled', 'Valencia City National High School', 'Editha Lagumbay', '0975 118 6642', 'Poblacion, Valencia City, Bukidnon'],
        ['Shaira', 'Magbanua', 'N.', 'F', '2008-02-11', 'Grade 12', 'Sampaguita', 'GAS', 'Enrolled', 'Bukidnon National High School', 'Fely Magbanua', '0917 662 9910', 'Bagontaas, Valencia City, Bukidnon'],
        ['Dindo', 'Napalit', 'M.', 'M', '2009-07-07', 'Grade 11', 'Rizal', 'TVL-ICT', 'Enrolled', 'San Isidro National High School', 'Crispin Napalit', '0935 442 7716', 'Sinayawan, Valencia City, Bukidnon'],
        ['Chriszel', 'Obenza', 'T.', 'F', '2010-03-02', 'Grade 10', 'Del Pilar', '', 'Enrolled', 'Valencia City National High School', 'Rhea Obenza', '0908 331 5590', 'Mailag, Valencia City, Bukidnon'],
        ['John Rey', 'Paquibot', 'A.', 'M', '2011-11-25', 'Grade 9', 'Mabini', '', 'Enrolled', 'Bagontaas National High School', 'Lito Paquibot', '0919 224 8873', 'Tongantongan, Valencia City, Bukidnon'],
        ['Angelica', 'Quijano', 'V.', 'F', '2012-01-16', 'Grade 8', 'Bonifacio', '', 'Enrolled', 'Valencia City National High School', 'Salve Quijano', '0997 810 4423', 'Afga, Valencia City, Bukidnon'],
        ['Bryan', 'Roa', 'K.', 'M', '2013-09-04', 'Grade 7', 'Luna', '', 'Enrolled', 'Bukidnon National High School', 'Noel Roa', '0917 553 0098', 'Lumbayao, Valencia City, Bukidnon'],
        ['Kateleen', 'Salazar', 'S.', 'F', '2008-05-08', 'Grade 12', 'Rizal', 'STEM', 'Enrolled', 'Valencia City National High School', 'Divina Salazar', '0921 447 2210', 'Poblacion, Valencia City, Bukidnon'],
        ['Emmanuel', 'Tumapon', 'D.', 'M', '2009-12-30', 'Grade 11', 'Sampaguita', 'HUMSS', 'Enrolled', 'Lurugan National High School', 'Adela Tumapon', '0916 002 7714', 'Lurugan, Valencia City, Bukidnon'],
        ['Richelle', 'Ubalde', 'M.', 'F', '2010-06-21', 'Grade 10', 'Del Pilar', '', 'Enrolled', 'Valencia City National High School', 'Gina Ubalde', '0938 776 1120', 'Bagontaas, Valencia City, Bukidnon'],
        ['Jerome', 'Valdez', 'P.', 'M', '2011-02-27', 'Grade 9', 'Mabini', '', 'Enrolled', 'San Isidro National High School', 'Rey Valdez', '0917 881 3365', 'Sinayawan, Valencia City, Bukidnon'],
        ['Hannah Grace', 'Wenceslao', 'L.', 'F', '2012-08-13', 'Grade 8', 'Bonifacio', '', 'Enrolled', 'Valencia City National High School', 'Cecile Wenceslao', '0905 114 7782', 'Mailag, Valencia City, Bukidnon'],
        ['Aldrin', 'Yacapin', 'C.', 'M', '2013-05-19', 'Grade 7', 'Luna', '', 'Enrolled', 'Bagontaas National High School', 'Ferdinand Yacapin', '0929 663 4417', 'Tongantongan, Valencia City, Bukidnon'],
        ['Nova Lyn', 'Zafra', 'B.', 'F', '2009-10-26', 'Grade 11', 'Sampaguita', 'ABM', 'Enrolled', 'Valencia City National High School', 'Marilou Zafra', '0918 337 9905', 'Poblacion, Valencia City, Bukidnon'],
        ['Reymart', 'Ampo', 'J.', 'M', '2008-08-01', 'Grade 12', 'Rizal', 'TVL-ICT', 'Graduated', 'Bukidnon National High School', 'Nestor Ampo', '0917 442 8836', 'Lumbayao, Valencia City, Bukidnon'],
    ];

    $docs = ['SF10 (Form 137)', 'SF9 (Report Card)', 'Elementary Record', 'PSA Birth Certificate', 'Good Moral Certificate', 'Transfer Credentials'];
    $cabinetLetters = ['A', 'B', 'C', 'D'];

    $credIssues = [
        5 => ['SF10 (Form 137)', 'Pending', 'Awaiting copy from previous school'],
        8 => ['Transfer Credentials', 'Pending', 'Prepared upon leaving'],
        9 => ['Elementary Record', 'Missing', 'To be requested from elementary school'],
        13 => ['Good Moral Certificate', 'Missing', 'Pending guidance clearance'],
        15 => ['PSA Birth Certificate', 'Pending', 'Parent advised to submit PSA copy'],
        17 => ['Good Moral Certificate', 'Missing', ''],
        21 => ['SF10 (Form 137)', 'Pending', 'Awaiting copy from previous school'],
        26 => ['Transfer Credentials', 'Pending', 'Prepared upon leaving'],
    ];

    $insertStudent = $pdo->prepare(
        'INSERT INTO students (lrn, first_name, middle_name, last_name, sex, birth_date, grade_level, section, strand, school_year, status, guardian_name, guardian_contact, address, previous_school, record_code, cabinet, shelf, folder_no, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertCred = $pdo->prepare(
        'INSERT INTO credentials (student_id, doc_type, status, date_received, remarks) VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($students as $i => $s) {
        [$fn, $ln, $mn, $sex, $bd, $grade, $section, $strand, $status, $prev, $guardian, $contact, $addr] = $s;
        $id = $i + 1;
        $lrn = sprintf('1360124%05d', 10230 + $i * 37 % 80000);
        $code = sprintf('REC-2026-%04d', $id);
        $cabinet = $cabinetLetters[$id % 4] . '-' . sprintf('%02d', ($id % 12) + 1);
        $shelf = 'Shelf ' . (($id % 5) + 1);
        $folder = sprintf('Folder %03d', $id * 3);
        $created = sprintf('2026-0%d-1%d 09:%02d:00', 6 + ($id % 3), $id % 8, 10 + $id % 40);

        $insertStudent->execute([
            $lrn, $fn, $mn, $ln, $sex, $bd, $grade, $section, $strand, '2026-2027', $status,
            $guardian, $contact, $addr, $prev, $code, $cabinet, $shelf, $folder,
            2, $created, $created,
        ]);
        $studentId = (int) $pdo->lastInsertId();

        foreach ($docs as $d => $doc) {
            $credStatus = 'Complete';
            $received = sprintf('2026-06-%02d', (($id + $d) % 20) + 1);
            $remarks = 'On file';

            $issue = $credIssues[$id] ?? null;
            if ($issue !== null && $issue[0] === $doc) {
                [, $credStatus, $remarks] = $issue;
                $received = null;
            } elseif ($doc === 'Transfer Credentials' && $status === 'Enrolled') {
                $remarks = 'Not required unless transferring';
            }

            $insertCred->execute([$studentId, $doc, $credStatus, $received, $remarks]);
        }
    }

    $requests = [
        [1, 'SF10 (Form 137)', 'Passport application', 'Marisol Abellana', 'Student', '0917 214 8890', 2, 'Released', '2026-10-02', '2026-10-03', '2026-10-05', 25, 'Released to student'],
        [3, 'Certificate of Enrollment', 'Visa application', 'Kryzha Mae Cabanillas', 'Student', '0906 118 2245', 3, 'Released', '2026-10-07', '2026-10-09', '2026-10-08', 20, ''],
        [2, 'SF9 (Form 138)', 'Scholarship application', 'Efren Bacalso', 'Parent', '0918 552 7741', 3, 'Processing', '2026-11-03', '2026-11-12', null, 20, 'For principal signature'],
        [5, 'Certificate of Good Moral', 'Local employment', 'Mercy Emborong', 'Parent', '0977 264 0018', 2, 'Ready for Release', '2026-11-05', '2026-11-10', null, 20, 'Awaiting pick-up'],
        [7, 'Certificate of Enrollment', 'TESDA enrollment', 'Liezl Gacusan', 'Student', '0939 887 5520', 3, 'Pending', '2026-11-09', '2026-11-18', null, 20, ''],
        [9, 'SF10 (Form 137)', 'School transfer', 'Jessa Mae Isidro', 'Student', '0961 224 8890', 2, 'Processing', '2026-11-10', '2026-11-20', null, 25, 'Certified true copy prepared'],
        [13, 'Certificate of Good Moral', 'Visa application', 'Fely Magbanua', 'Parent', '0917 662 9910', 3, 'Pending', '2026-11-12', '2026-11-24', null, 20, ''],
        [15, 'SF9 (Form 138)', 'Employment abroad', 'Chriszel Obenza', 'Parent', '0908 331 5590', 2, 'Ready for Release', '2026-11-13', '2026-11-20', null, 20, 'For release by registrar'],
        [17, 'Certificate of Enrollment', 'Scholarship application', 'Angelica Quijano', 'Student', '0997 810 4423', 3, 'Pending', '2026-11-16', '2026-11-27', null, 20, ''],
        [19, 'SF10 (Form 137)', 'College application', 'Kateleen Salazar', 'Student', '0921 447 2210', 2, 'Released', '2026-10-20', '2026-10-23', '2026-10-22', 25, ''],
        [21, 'Certificate of Good Moral', 'Passport application', 'Gina Ubalde', 'Parent', '0938 776 1120', 3, 'Processing', '2026-11-17', '2026-11-26', null, 20, ''],
        [8, 'Certificate of Transfer', 'School transfer', 'Arnel Hinautan', 'Student', '0917 909 3341', 2, 'Released', '2026-10-15', '2026-10-16', '2026-10-16', 30, 'Endorsed to receiving school'],
        [23, 'Certificate of Completion', 'Local employment', 'Cecile Wenceslao', 'Parent', '0905 114 7782', 3, 'Cancelled', '2026-11-04', '2026-11-11', null, 20, 'Request withdrawn'],
        [25, 'SF10 (Form 137)', 'Visa application', 'Nova Lyn Zafra', 'Student', '0918 337 9905', 2, 'Pending', '2026-11-18', '2026-11-28', null, 25, ''],
    ];

    $insertReq = $pdo->prepare(
        'INSERT INTO requests (request_no, student_id, document_type, purpose, requester_name, relationship, requester_contact, assigned_to, status, date_requested, date_needed, date_released, fee, remarks, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($requests as $i => $r) {
        $no = sprintf('REQ-2026-%04d', $i + 1);
        [$sid, $doc, $purpose, $reqBy, $rel, $contact, $assignee, $status, $dateReq, $needed, $released, $fee, $remarks] = $r;
        $insertReq->execute([
            $no, $sid, $doc, $purpose, $reqBy, $rel, $contact, $assignee, $status,
            $dateReq, $needed, $released, $fee, $remarks, 3, $dateReq . ' 08:30:00', $dateReq . ' 08:30:00',
        ]);
    }

    $followups = [
        [5, 'SF10 from previous school not yet forwarded', 'Bagontaas National High School', 'Ms. Rowena Salas', 'Email', '2026-11-06', 'Send second follow-up letter with request slip attached', '2026-11-14', 'Open'],
        [9, 'Incomplete elementary record upon enrollment', 'San Isidro Elementary School', 'Mr. Danilo Cruz', 'Call', '2026-11-10', 'Call again and request certified copy', '2026-11-21', 'In Progress'],
        [15, 'PSA birth certificate copy not yet submitted', 'Parent - Mer Mercy Obenza', 'Rhea Obenza', 'SMS', '2026-11-12', 'Remind parent during next office visit', '2026-11-24', 'Open'],
        [17, 'Good moral certificate pending guidance clearance', 'Guidance Office', 'Ms. Perla Ignacio', 'Portal', '2026-11-13', 'Coordinate with guidance office for clearance', '2026-11-20', 'In Progress'],
        [3, 'Form 137 requested by receiving school for transfer', 'San Isidro National High School', 'Mrs. Liza Manlapaz', 'Email', '2026-11-08', 'Await school confirmation of receipt', '2026-11-18', 'Resolved'],
        [21, 'SF10 received from previous school after follow-up', 'Valencia City National High School', 'Mr. Jun Almonte', 'Call', '2026-11-02', 'File received copy and update checklist', '2026-11-06', 'Resolved'],
        [13, 'Missing good moral certificate', 'Guidance Office', 'Ms. Perla Ignacio', 'Portal', '2026-11-15', 'Schedule certificate signing with principal', '2026-11-25', 'Open'],
    ];
    $insertFu = $pdo->prepare(
        'INSERT INTO followups (student_id, concern, school_contacted, contact_person, method, followup_date, next_action, next_followup_date, status, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($followups as $f) {
        [$sid, $concern, $school, $person, $method, $date, $next, $nextDate, $status] = $f;
        $insertFu->execute([$sid, $concern, $school, $person, $method, $date, $next, $nextDate, $status, 3, $date . ' 10:15:00']);
    }

    $certs = [
        [1, 'Certificate of Enrollment', 'Marisol Abellana', 'Passport application', 2, '2026-10-05'],
        [3, 'Certificate of Good Moral', 'Kryzha Mae Cabanillas', 'Visa application', 2, '2026-10-08'],
        [7, 'Certificate of Enrollment', 'Liezl Gacusan', 'TESDA enrollment', 2, '2026-10-12'],
        [8, 'Certificate of Transfer', 'Arnel Hinautan', 'School transfer', 2, '2026-10-16'],
        [19, 'Certificate of Enrollment', 'Kateleen Salazar', 'Scholarship application', 2, '2026-10-22'],
        [2, 'Certificate of Good Moral', 'Jomar Bacalso', 'Local employment', 2, '2026-11-06'],
    ];
    $insertCert = $pdo->prepare(
        'INSERT INTO certifications (cert_no, student_id, cert_type, issued_to, purpose, issued_by, date_issued) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($certs as $i => $c) {
        [$sid, $type, $to, $purpose, $by, $date] = $c;
        $insertCert->execute([sprintf('CERT-2026-%04d', $i + 1), $sid, $type, $to, $purpose, $by, $date]);
    }

    $transfers = [
        [8, 'Outgoing', 'Bukidnon National High School', '2026-10-16', 'Family relocated to Malaybalay City', 'Completed', 'Credentials endorsed; awaiting acknowledgment'],
        [5, 'Incoming', 'Bagontaas National High School', '2026-06-02', 'Enrollment transfer', 'Documents Pending', 'SF10 still to follow'],
        [9, 'Incoming', 'San Isidro National High School', '2026-06-03', 'Enrollment transfer', 'Documents Pending', 'Elementary record missing'],
        [1, 'Incoming', 'Bukidnon National High School', '2026-06-01', 'Enrollment transfer', 'Completed', 'All credentials verified'],
    ];
    $insertTr = $pdo->prepare(
        'INSERT INTO transfers (student_id, direction, counterpart_school, effective_date, reason, status, notes, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($transfers as $t) {
        [$sid, $dir, $school, $date, $reason, $status, $notes] = $t;
        $insertTr->execute([$sid, $dir, $school, $date, $reason, $status, $notes, 2, $date . ' 11:00:00']);
    }

    $logs = [
        [2, 'Student record created', 'Added student record REC-2026-0024 (Aldrin Yacapin)'],
        [3, 'Request updated', 'REQ-2026-0008 marked Ready for Release'],
        [2, 'Certificate issued', 'CERT-2026-0006 issued to Jomar Bacalso'],
        [3, 'Follow-up logged', 'Follow-up with Bagontaas National High School for student REC-2026-0005'],
        [1, 'User signed in', 'Administrator signed in from registrar workstation'],
    ];
    $insertLog = $pdo->prepare('INSERT INTO activity (user_id, action, details, created_at) VALUES (?, ?, ?, ?)');
    foreach ($logs as $i => $l) {
        [$uid, $action, $details] = $l;
        $insertLog->execute([$uid, $action, $details, sprintf('2026-11-%02d %02d:%02d:00', 17 + ($i % 3), 8 + $i, 12 + $i * 7)]);
    }

    $pdo->commit();
}
