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
    db_upgrade($pdo);

    return $pdo;
}

function db_upgrade(PDO $pdo): void
{
    $userColumns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    foreach ([
        'session_token' => 'TEXT',
        'session_seen_at' => 'TEXT',
        'failed_logins' => 'INTEGER NOT NULL DEFAULT 0',
        'locked_until' => 'TEXT',
    ] as $column => $definition) {
        if (!in_array($column, $userColumns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $column $definition");
        }
    }
    foreach (['first_name', 'middle_name', 'last_name'] as $column) {
        if (!in_array($column, $userColumns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $column TEXT NOT NULL DEFAULT ''");
        }
    }
    $legacyNames = $pdo->query("SELECT id, full_name FROM users WHERE TRIM(first_name) = '' AND TRIM(last_name) = ''")->fetchAll();
    $backfillName = $pdo->prepare('UPDATE users SET first_name = ?, middle_name = ?, last_name = ? WHERE id = ?');
    foreach ($legacyNames as $row) {
        [$firstName, $middleName, $lastName] = split_person_name((string) $row['full_name']);
        $backfillName->execute([$firstName, $middleName, $lastName, (int) $row['id']]);
    }

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS cabinets (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT NOT NULL UNIQUE,
    grade_level TEXT,
    capacity    INTEGER NOT NULL DEFAULT 10,
    notes       TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS shelves (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    cabinet_id  INTEGER NOT NULL REFERENCES cabinets(id) ON DELETE CASCADE,
    code        TEXT NOT NULL,
    grade_level TEXT,
    strand      TEXT,
    section     TEXT,
    capacity    INTEGER NOT NULL DEFAULT 20,
    created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (cabinet_id, code)
);

CREATE TABLE IF NOT EXISTS folders (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    shelf_id   INTEGER NOT NULL REFERENCES shelves(id) ON DELETE CASCADE,
    number     TEXT NOT NULL,
    capacity   INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (shelf_id, number)
);
SQL);

    $cabinetColumns = array_column($pdo->query('PRAGMA table_info(cabinets)')->fetchAll(), 'name');
    if (!in_array('grade_level', $cabinetColumns, true)) {
        $pdo->exec('ALTER TABLE cabinets ADD COLUMN grade_level TEXT');
    }

    // Retired strand label: keep every existing record on "TVL".
    $pdo->exec("UPDATE students SET strand = 'TVL' WHERE strand = 'TVL-ICT'");
    $pdo->exec("UPDATE shelves SET strand = 'TVL' WHERE strand = 'TVL-ICT'");

    if ((int) $pdo->query('SELECT COUNT(*) FROM cabinets')->fetchColumn() > 0) {
        db_storage_model_upgrade($pdo);
        return;
    }

    // Normalise legacy "Folder 001" values to "001" so the registry and the
    // existing locator text agree.
    $legacy = $pdo->query("SELECT DISTINCT folder_no FROM students WHERE TRIM(COALESCE(folder_no, '')) <> ''")->fetchAll(PDO::FETCH_COLUMN);
    $fixFolder = $pdo->prepare('UPDATE students SET folder_no = ? WHERE folder_no = ?');
    foreach ($legacy as $raw) {
        $number = trim((string) preg_replace('/^folder\s*/i', '', (string) $raw));
        if ($number !== '' && ctype_digit($number)) {
            $number = str_pad($number, 3, '0', STR_PAD_LEFT);
        }
        if ($number === '' || $number === $raw) {
            continue;
        }
        $fixFolder->execute([$number, $raw]);
    }

    $cabinetCodes = $pdo->query("SELECT DISTINCT TRIM(cabinet) FROM students WHERE TRIM(COALESCE(cabinet, '')) <> '' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
    if (!$cabinetCodes) {
        return;
    }

    $pdo->beginTransaction();
    try {
        $insCabinet = $pdo->prepare('INSERT OR IGNORE INTO cabinets (code) VALUES (?)');
        foreach ($cabinetCodes as $code) {
            $insCabinet->execute([$code]);
        }

        $cabinetId = $pdo->prepare('SELECT id FROM cabinets WHERE code = ?');
        $insShelf = $pdo->prepare('INSERT OR IGNORE INTO shelves (cabinet_id, code, grade_level, strand, section) VALUES (?, ?, ?, ?, ?)');
        $sectionProbe = $pdo->prepare(
            "SELECT grade_level, strand, section FROM students
             WHERE cabinet = ? AND shelf = ? AND TRIM(COALESCE(section, '')) <> ''
             GROUP BY grade_level, strand, section
             LIMIT 2"
        );
        $shelfPairs = $pdo->query(
            "SELECT DISTINCT TRIM(cabinet) AS cabinet, TRIM(shelf) AS shelf FROM students
             WHERE TRIM(COALESCE(cabinet, '')) <> '' AND TRIM(COALESCE(shelf, '')) <> ''
             ORDER BY 1, 2"
        )->fetchAll();
        foreach ($shelfPairs as $pair) {
            $cabinetId->execute([$pair['cabinet']]);
            $cid = (int) $cabinetId->fetchColumn();
            if ($cid === 0) {
                continue;
            }
            $sectionProbe->execute([$pair['cabinet'], $pair['shelf']]);
            $sections = $sectionProbe->fetchAll();
            $grade = $strand = $section = null;
            if (count($sections) === 1) {
                $grade = $sections[0]['grade_level'];
                $strand = $sections[0]['strand'];
                $section = $sections[0]['section'];
            }
            $insShelf->execute([$cid, $pair['shelf'], $grade, $strand, $section]);
        }

        $shelfId = $pdo->prepare('SELECT sh.id FROM shelves sh JOIN cabinets c ON c.id = sh.cabinet_id WHERE c.code = ? AND sh.code = ?');
        $insFolder = $pdo->prepare('INSERT OR IGNORE INTO folders (shelf_id, number) VALUES (?, ?)');
        $folderPairs = $pdo->query(
            "SELECT DISTINCT TRIM(cabinet) AS cabinet, TRIM(shelf) AS shelf, TRIM(folder_no) AS folder_no
             FROM students
             WHERE TRIM(COALESCE(cabinet, '')) <> '' AND TRIM(COALESCE(shelf, '')) <> '' AND TRIM(COALESCE(folder_no, '')) <> ''
             ORDER BY 1, 2, 3"
        )->fetchAll();
        foreach ($folderPairs as $pair) {
            $shelfId->execute([$pair['cabinet'], $pair['shelf']]);
            $sid = (int) $shelfId->fetchColumn();
            if ($sid === 0) {
                continue;
            }
            $insFolder->execute([$sid, $pair['folder_no']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    db_storage_model_upgrade($pdo);
}

function db_storage_model_upgrade(PDO $pdo): void
{
    $done = $pdo->query("SELECT value FROM settings WHERE key = 'storage_folders_per_student'")->fetchColumn();
    if ($done === '1') {
        return;
    }

    $pdo->exec(
        "UPDATE cabinets SET grade_level = (
            SELECT sh.grade_level FROM shelves sh
            WHERE sh.cabinet_id = cabinets.id AND TRIM(COALESCE(sh.grade_level, '')) <> ''
            GROUP BY sh.grade_level ORDER BY COUNT(*) DESC LIMIT 1
         )
         WHERE grade_level IS NULL OR grade_level = ''"
    );
    $pdo->exec(
        "UPDATE cabinets SET grade_level = (
            SELECT st.grade_level FROM students st
            WHERE st.cabinet = cabinets.code AND TRIM(COALESCE(st.grade_level, '')) <> ''
            GROUP BY st.grade_level ORDER BY COUNT(*) DESC LIMIT 1
         )
         WHERE grade_level IS NULL OR grade_level = ''"
    );
    $pdo->exec('UPDATE folders SET capacity = 1 WHERE capacity <> 1');

    $cabinets = $pdo->query('SELECT * FROM cabinets')->fetchAll();
    foreach ($cabinets as $cabinet) {
        $shelves = $pdo->prepare('SELECT * FROM shelves WHERE cabinet_id = ?');
        $shelves->execute([$cabinet['id']]);
        $mixed = false;
        foreach ($shelves->fetchAll() as $shelf) {
            if (trim((string) ($shelf['section'] ?? '')) === '') {
                $mixed = true;
                break;
            }
        }
        if (!$mixed) {
            continue;
        }

        $groups = $pdo->prepare(
            "SELECT grade_level, COALESCE(strand, '') AS strand, section
             FROM students
             WHERE cabinet = ? AND TRIM(COALESCE(section, '')) <> ''
             GROUP BY grade_level, COALESCE(strand, ''), section
             ORDER BY CAST(REPLACE(grade_level, 'Grade ', '') AS INTEGER),
                      COALESCE(strand, '') COLLATE NOCASE, section COLLATE NOCASE"
        );
        $groups->execute([$cabinet['code']]);
        $sections = $groups->fetchAll();
        if (!$sections || count($sections) > (int) $cabinet['capacity']) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE students SET shelf = '', folder_no = '' WHERE cabinet = ?")->execute([$cabinet['code']]);
            $pdo->prepare('DELETE FROM shelves WHERE cabinet_id = ?')->execute([$cabinet['id']]);

            $insShelf = $pdo->prepare('INSERT INTO shelves (cabinet_id, code, grade_level, strand, section, capacity) VALUES (?, ?, ?, ?, ?, ?)');
            $insFolder = $pdo->prepare('INSERT INTO folders (shelf_id, number, capacity) VALUES (?, ?, 1)');
            $update = $pdo->prepare('UPDATE students SET shelf = ?, folder_no = ? WHERE id = ?');
            $pick = $pdo->prepare(
                "SELECT id FROM students
                 WHERE cabinet = ? AND grade_level = ? AND COALESCE(strand, '') = ? AND section = ?
                 ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE, id"
            );

            foreach ($sections as $index => $section) {
                $shelfCode = 'Shelf ' . ($index + 1);
                $insShelf->execute([
                    $cabinet['id'], $shelfCode, $section['grade_level'],
                    $section['strand'], $section['section'], SHELF_FOLDER_LIMIT,
                ]);
                $shelfId = (int) $pdo->lastInsertId();

                $pick->execute([$cabinet['code'], $section['grade_level'], $section['strand'], $section['section']]);
                foreach ($pick->fetchAll() as $folderIndex => $student) {
                    $number = sprintf('%03d', $folderIndex + 1);
                    $insFolder->execute([$shelfId, $number]);
                    $update->execute([$shelfCode, $number, $student['id']]);
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('storage_folders_per_student', '1')")->execute();
}

function db_install(PDO $pdo): void
{
    $schema = <<<SQL
CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    full_name     TEXT NOT NULL,
    first_name    TEXT NOT NULL DEFAULT '',
    middle_name   TEXT NOT NULL DEFAULT '',
    last_name     TEXT NOT NULL DEFAULT '',
    role          TEXT NOT NULL DEFAULT 'staff',
    email         TEXT,
    is_active     INTEGER NOT NULL DEFAULT 1,
    last_login    TEXT,
    session_token TEXT,
    session_seen_at TEXT,
    failed_logins INTEGER NOT NULL DEFAULT 0,
    locked_until  TEXT,
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
        'school_name' => 'Valencia National High School',
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

    $pdo->commit();
}
