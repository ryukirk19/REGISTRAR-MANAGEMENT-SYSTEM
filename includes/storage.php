<?php
declare(strict_types=1);

const CABINET_SHELF_LIMIT = 10;
const SHELF_FOLDER_LIMIT = 20;
const FOLDER_RECORD_LIMIT = 1;
const GRADE_ENROLLMENT_LIMIT = 1000;
const STRAND_ENROLLMENT_LIMIT = 500;

function storage_cabinets(): array
{
    return db()->query(
        "SELECT c.*,
            (SELECT COUNT(*) FROM shelves sh WHERE sh.cabinet_id = c.id) AS shelf_count,
            (SELECT COUNT(*) FROM students st WHERE st.cabinet = c.code) AS record_count
         FROM cabinets c
         ORDER BY c.code COLLATE NOCASE"
    )->fetchAll();
}

function storage_shelves(?int $cabinetId = null): array
{
    $sql = "SELECT sh.*, c.code AS cabinet_code,
            (SELECT COUNT(*) FROM folders f WHERE f.shelf_id = sh.id) AS folder_count,
            (SELECT COUNT(*) FROM students st WHERE st.cabinet = c.code AND st.shelf = sh.code) AS record_count
         FROM shelves sh
         JOIN cabinets c ON c.id = sh.cabinet_id";
    $params = [];
    if ($cabinetId !== null) {
        $sql .= ' WHERE sh.cabinet_id = :cabinet';
        $params[':cabinet'] = $cabinetId;
    }
    $sql .= " ORDER BY c.code COLLATE NOCASE, CAST(REPLACE(sh.code, 'Shelf ', '') AS INTEGER), sh.code COLLATE NOCASE";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function storage_folders(?int $shelfId = null): array
{
    $sql = "SELECT f.*, sh.code AS shelf_code, sh.section AS shelf_section,
            c.code AS cabinet_code, c.grade_level AS cabinet_grade,
            (SELECT COUNT(*) FROM students st
                WHERE st.cabinet = c.code AND st.shelf = sh.code AND st.folder_no = f.number) AS record_count,
            (SELECT st.id FROM students st
                WHERE st.cabinet = c.code AND st.shelf = sh.code AND st.folder_no = f.number
                ORDER BY st.id LIMIT 1) AS occupant_id,
            (SELECT st.last_name || ', ' || st.first_name
                    || CASE WHEN TRIM(COALESCE(st.middle_name, '')) <> ''
                        THEN ' ' || SUBSTR(TRIM(st.middle_name), 1, 1) || '.' ELSE '' END
                FROM students st
                WHERE st.cabinet = c.code AND st.shelf = sh.code AND st.folder_no = f.number
                ORDER BY st.id LIMIT 1) AS occupant
         FROM folders f
         JOIN shelves sh ON sh.id = f.shelf_id
         JOIN cabinets c ON c.id = sh.cabinet_id";
    $params = [];
    if ($shelfId !== null) {
        $sql .= ' WHERE f.shelf_id = :shelf';
        $params[':shelf'] = $shelfId;
    }
    $sql .= " ORDER BY c.code COLLATE NOCASE,
            CAST(REPLACE(sh.code, 'Shelf ', '') AS INTEGER),
            (occupant IS NULL), occupant COLLATE NOCASE,
            CAST(f.number AS INTEGER), f.number COLLATE NOCASE";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function storage_assignment(array $fields): ?array
{
    $cabinet = trim((string) ($fields['cabinet'] ?? ''));
    $shelf = trim((string) ($fields['shelf'] ?? ''));
    $folder = trim((string) ($fields['folder_no'] ?? ''));
    if ($cabinet === '' || $shelf === '' || $folder === '') {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT f.id AS folder_id, f.number, f.capacity AS folder_capacity,
                sh.id AS shelf_id, sh.code AS shelf_code, sh.section, sh.grade_level, sh.strand,
                sh.capacity AS shelf_capacity,
                c.id AS cabinet_id, c.code AS cabinet_code, c.capacity AS cabinet_capacity,
                c.grade_level AS cabinet_grade
         FROM folders f
         JOIN shelves sh ON sh.id = f.shelf_id
         JOIN cabinets c ON c.id = sh.cabinet_id
         WHERE c.code = ? AND sh.code = ? AND f.number = ?"
    );
    $stmt->execute([$cabinet, $shelf, $folder]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function storage_validate(array $fields, ?int $ignoreStudentId = null): ?string
{
    $trio = [
        trim((string) ($fields['cabinet'] ?? '')),
        trim((string) ($fields['shelf'] ?? '')),
        trim((string) ($fields['folder_no'] ?? '')),
    ];
    $filled = array_filter($trio, static fn (string $v): bool => $v !== '');
    if (!$filled) {
        return null;
    }
    if (count($filled) < 3) {
        return 'Choose a cabinet, shelf, and folder together — or leave all three blank.';
    }

    $assignment = storage_assignment($fields);
    if (!$assignment) {
        return 'That cabinet, shelf, or folder is not in the storage registry yet. Add it under Storage & cabinets first.';
    }

    $sql = 'SELECT COUNT(*) FROM students WHERE cabinet = ? AND shelf = ? AND folder_no = ?';
    $params = [$trio[0], $trio[1], $trio[2]];
    if ($ignoreStudentId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $ignoreStudentId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $used = (int) $stmt->fetchColumn();
    if ($used >= (int) $assignment['folder_capacity']) {
        return 'Folder ' . $assignment['number'] . ' is already assigned to a student. Choose an empty folder or add a new one.';
    }

    $shelfSection = trim((string) ($assignment['section'] ?? ''));
    if ($shelfSection === '') {
        return 'Shelf ' . $assignment['shelf_code'] . ' is not reserved for a section yet. Assign its section under Storage & cabinets first.';
    }

    $studentSection = trim((string) ($fields['section'] ?? ''));
    if ($studentSection !== $shelfSection) {
        return 'Shelf ' . $assignment['shelf_code'] . ' is reserved for ' . $shelfSection . '. Set the section to ' . $shelfSection . ' or pick another shelf.';
    }

    $cabinetGrade = trim((string) ($assignment['cabinet_grade'] ?? ''));
    $studentGrade = trim((string) ($fields['grade_level'] ?? ''));
    if ($cabinetGrade === '') {
        return 'Cabinet ' . $assignment['cabinet_code'] . ' has no grade level set. Assign one under Storage & cabinets first.';
    }
    if ($studentGrade !== '' && $studentGrade !== $cabinetGrade) {
        return 'Cabinet ' . $assignment['cabinet_code'] . ' is reserved for ' . $cabinetGrade . '. Pick a cabinet for ' . $studentGrade . '.';
    }

    return null;
}

function enrollment_grade_count(string $schoolYear, string $grade): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM students WHERE school_year = ? AND grade_level = ?');
    $stmt->execute([$schoolYear, $grade]);
    return (int) $stmt->fetchColumn();
}

function enrollment_strand_count(string $schoolYear, string $strand): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM students WHERE school_year = ? AND strand = ?');
    $stmt->execute([$schoolYear, $strand]);
    return (int) $stmt->fetchColumn();
}

function storage_next_cabinet_code(): string
{
    $used = array_map('strtoupper', db()->query('SELECT code FROM cabinets')->fetchAll(PDO::FETCH_COLUMN));
    for ($i = 1; $i <= 999; $i++) {
        $code = sprintf('A-%02d', $i);
        if (!in_array($code, $used, true)) {
            return $code;
        }
    }
    return 'A-' . sprintf('%03d', count($used) + 1);
}

function storage_next_shelf_code(int $cabinetId): string
{
    $stmt = db()->prepare('SELECT code FROM shelves WHERE cabinet_id = ?');
    $stmt->execute([$cabinetId]);
    $used = $stmt->fetchAll(PDO::FETCH_COLUMN);
    for ($i = 1; $i <= 999; $i++) {
        $code = 'Shelf ' . $i;
        if (!in_array($code, $used, true)) {
            return $code;
        }
    }
    return 'Shelf ' . (count($used) + 1);
}

function storage_next_folder_no(int $shelfId): string
{
    $stmt = db()->prepare('SELECT number FROM folders WHERE shelf_id = ?');
    $stmt->execute([$shelfId]);
    $used = $stmt->fetchAll(PDO::FETCH_COLUMN);
    for ($i = 1; $i <= 999; $i++) {
        $number = sprintf('%03d', $i);
        if (!in_array($number, $used, true)) {
            return $number;
        }
    }
    return '999';
}

function storage_sections(): array
{
    return db()->query(
        "SELECT grade_level, strand, section, COUNT(*) AS n
         FROM students
         WHERE TRIM(COALESCE(section, '')) <> ''
         GROUP BY grade_level, strand, section
         ORDER BY CAST(REPLACE(grade_level, 'Grade ', '') AS INTEGER), COALESCE(strand, '') COLLATE NOCASE, section COLLATE NOCASE"
    )->fetchAll();
}
