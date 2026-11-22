<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/layout.php';
require_manager();

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_cabinet') {
        $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
        $grade = trim((string) ($_POST['grade_level'] ?? ''));
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{1,14}$/', $code)) {
            flash('error', 'Cabinet codes use letters, numbers, and dashes only (2–15 characters).');
        } elseif (!in_array($grade, grade_levels(), true)) {
            flash('error', 'Choose the grade level this cabinet is for.');
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM cabinets WHERE code = ?');
            $stmt->execute([$code]);
            if ((int) $stmt->fetchColumn() > 0) {
                flash('error', 'Cabinet ' . $code . ' already exists.');
            } else {
                $insert = $pdo->prepare('INSERT INTO cabinets (code, grade_level, capacity) VALUES (?, ?, ?)');
                $insert->execute([$code, $grade, CABINET_SHELF_LIMIT]);
                log_activity('Cabinet added', $code . ' · ' . $grade);
                flash('success', 'Cabinet ' . $code . ' is ready for ' . $grade . ' shelves.');
            }
        }
    }

    if ($action === 'set_cabinet_grade') {
        $cabinetId = (int) ($_POST['cabinet_id'] ?? 0);
        $grade = trim((string) ($_POST['grade_level'] ?? ''));
        $stmt = $pdo->prepare('SELECT * FROM cabinets WHERE id = ?');
        $stmt->execute([$cabinetId]);
        $cabinet = $stmt->fetch();
        if (!$cabinet) {
            flash('error', 'That cabinet could not be found.');
        } elseif (trim((string) ($cabinet['grade_level'] ?? '')) !== '') {
            flash('error', 'Cabinet ' . $cabinet['code'] . ' already has a grade level.');
        } elseif (!in_array($grade, grade_levels(), true)) {
            flash('error', 'Choose a grade level for cabinet ' . $cabinet['code'] . '.');
        } else {
            $pdo->prepare('UPDATE cabinets SET grade_level = ? WHERE id = ?')->execute([$grade, $cabinetId]);
            log_activity('Cabinet grade set', $cabinet['code'] . ' · ' . $grade);
            flash('success', 'Cabinet ' . $cabinet['code'] . ' is now reserved for ' . $grade . '.');
        }
    }

    if ($action === 'add_shelf') {
        $cabinetId = (int) ($_POST['cabinet_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM cabinets WHERE id = ?');
        $stmt->execute([$cabinetId]);
        $cabinet = $stmt->fetch();

        $grade = trim((string) ($cabinet['grade_level'] ?? ''));
        $strand = trim((string) ($_POST['strand'] ?? ''));
        $section = trim((string) ($_POST['section'] ?? ''));

        $duplicate = false;
        if ($cabinet && $section !== '') {
            $dup = $pdo->prepare('SELECT COUNT(*) FROM shelves WHERE cabinet_id = ? AND LOWER(section) = LOWER(?)');
            $dup->execute([$cabinetId, $section]);
            $duplicate = (int) $dup->fetchColumn() > 0;
        }

        if (!$cabinet) {
            flash('error', 'That cabinet could not be found.');
        } elseif ($grade === '') {
            flash('error', 'Set the grade level for cabinet ' . $cabinet['code'] . ' before adding shelves.');
        } elseif (count(storage_shelves($cabinetId)) >= (int) $cabinet['capacity']) {
            flash('error', 'Cabinet ' . $cabinet['code'] . ' already has ' . (int) $cabinet['capacity'] . ' shelves. Add a new cabinet first.');
        } elseif ($section === '' || strlen($section) > 60) {
            flash('error', 'Each shelf is reserved for one section — enter the section name.');
        } elseif ($duplicate) {
            flash('error', 'Cabinet ' . $cabinet['code'] . ' already has a shelf for ' . $section . '.');
        } elseif (strlen($strand) > 40) {
            flash('error', 'The strand name is too long.');
        } else {
            $code = storage_next_shelf_code($cabinetId);
            $insert = $pdo->prepare('INSERT INTO shelves (cabinet_id, code, grade_level, strand, section, capacity) VALUES (?, ?, ?, ?, ?, ?)');
            $insert->execute([$cabinetId, $code, $grade, $strand, $section, SHELF_FOLDER_LIMIT]);
            log_activity('Shelf added', $cabinet['code'] . ' · ' . $code . ' · ' . $section);
            flash('success', $code . ' was added to cabinet ' . $cabinet['code'] . ' for ' . $section . '.');
        }
    }

    if ($action === 'add_folder') {
        $shelfId = (int) ($_POST['shelf_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT sh.*, c.code AS cabinet_code FROM shelves sh JOIN cabinets c ON c.id = sh.cabinet_id WHERE sh.id = ?');
        $stmt->execute([$shelfId]);
        $shelf = $stmt->fetch();

        if (!$shelf) {
            flash('error', 'That shelf could not be found.');
        } elseif (count(storage_folders($shelfId)) >= (int) $shelf['capacity']) {
            flash('error', $shelf['code'] . ' already has ' . (int) $shelf['capacity'] . ' folders. Add another shelf.');
        } else {
            $number = storage_next_folder_no($shelfId);
            $insert = $pdo->prepare('INSERT INTO folders (shelf_id, number, capacity) VALUES (?, ?, ?)');
            $insert->execute([$shelfId, $number, FOLDER_RECORD_LIMIT]);
            log_activity('Folder added', $shelf['cabinet_code'] . ' · ' . $shelf['code'] . ' · Folder ' . $number);
            flash('success', 'Folder ' . $number . ' was added to ' . $shelf['code'] . '.');
        }
    }

    if ($action === 'delete_cabinet') {
        $cabinetId = (int) ($_POST['cabinet_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM cabinets WHERE id = ?');
        $stmt->execute([$cabinetId]);
        $cabinet = $stmt->fetch();
        if (!$cabinet) {
            flash('error', 'That cabinet could not be found.');
        } elseif (count(storage_shelves($cabinetId)) > 0) {
            flash('error', 'Remove the shelves inside cabinet ' . $cabinet['code'] . ' first.');
        } else {
            $used = $pdo->prepare('SELECT COUNT(*) FROM students WHERE cabinet = ?');
            $used->execute([$cabinet['code']]);
            if ((int) $used->fetchColumn() > 0) {
                flash('error', 'Cabinet ' . $cabinet['code'] . ' is still assigned to student records.');
            } else {
                $pdo->prepare('DELETE FROM cabinets WHERE id = ?')->execute([$cabinetId]);
                log_activity('Cabinet removed', $cabinet['code']);
                flash('success', 'Cabinet ' . $cabinet['code'] . ' was removed.');
            }
        }
    }

    if ($action === 'delete_shelf') {
        $shelfId = (int) ($_POST['shelf_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT sh.*, c.code AS cabinet_code FROM shelves sh JOIN cabinets c ON c.id = sh.cabinet_id WHERE sh.id = ?');
        $stmt->execute([$shelfId]);
        $shelf = $stmt->fetch();
        if (!$shelf) {
            flash('error', 'That shelf could not be found.');
        } elseif (count(storage_folders($shelfId)) > 0) {
            flash('error', 'Remove the folders on ' . $shelf['code'] . ' first.');
        } else {
            $pdo->prepare('DELETE FROM shelves WHERE id = ?')->execute([$shelfId]);
            log_activity('Shelf removed', $shelf['cabinet_code'] . ' · ' . $shelf['code']);
            flash('success', $shelf['code'] . ' was removed from cabinet ' . $shelf['cabinet_code'] . '.');
        }
    }

    if ($action === 'delete_folder') {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $stmt = $pdo->prepare(
            'SELECT f.*, sh.code AS shelf_code, c.code AS cabinet_code
             FROM folders f JOIN shelves sh ON sh.id = f.shelf_id JOIN cabinets c ON c.id = sh.cabinet_id
             WHERE f.id = ?'
        );
        $stmt->execute([$folderId]);
        $folder = $stmt->fetch();
        if (!$folder) {
            flash('error', 'That folder could not be found.');
        } else {
            $used = $pdo->prepare('SELECT COUNT(*) FROM students WHERE cabinet = ? AND shelf = ? AND folder_no = ?');
            $used->execute([$folder['cabinet_code'], $folder['shelf_code'], $folder['number']]);
            if ((int) $used->fetchColumn() > 0) {
                flash('error', 'Folder ' . $folder['number'] . ' still holds student records.');
            } else {
                $pdo->prepare('DELETE FROM folders WHERE id = ?')->execute([$folderId]);
                log_activity('Folder removed', $folder['cabinet_code'] . ' · ' . $folder['shelf_code'] . ' · Folder ' . $folder['number']);
                flash('success', 'Folder ' . $folder['number'] . ' was removed.');
            }
        }
    }

    redirect('cabinets.php');
}

$cabinets = storage_cabinets();
$sections = storage_sections();
$shelvesByCabinet = [];
foreach (storage_shelves() as $shelf) {
    $shelvesByCabinet[(int) $shelf['cabinet_id']][] = $shelf;
}
$foldersByShelf = [];
foreach (storage_folders() as $folder) {
    $foldersByShelf[(int) $folder['shelf_id']][] = $folder;
}
$strandChoices = array_values(array_unique(array_filter(array_merge(
    array_slice(strands(), 1),
    array_column($sections, 'strand')
))));

app_header('Storage & Cabinets', 'cabinets.php');
?>
<div class="page-head">
    <div>
        <div class="eyebrow">Records vault</div>
        <h1>Storage &amp; cabinets</h1>
        <p class="page-sub">One cabinet per grade level, one shelf per section, one folder per student. A cabinet holds up to <?= CABINET_SHELF_LIMIT ?> section shelves, and each shelf up to <?= SHELF_FOLDER_LIMIT ?> student folders.</p>
    </div>
</div>

<form class="card" method="post" action="/cabinets.php" style="padding:14px 16px; margin-bottom:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_cabinet">
    <div class="toolbar">
        <div class="field" style="flex-direction:row; align-items:center; gap:8px">
            <label for="code" style="font-size:12.2px">New cabinet code</label>
            <input type="text" id="code" name="code" value="<?= e(storage_next_cabinet_code()) ?>" maxlength="15" style="height:36px; width:110px; border:1px solid var(--line-2); border-radius:9px; padding:0 10px; font:inherit; font-size:13px; background:#fff">
        </div>
        <div class="field" style="flex-direction:row; align-items:center; gap:8px">
            <label for="cab_grade" style="font-size:12.2px">For grade level</label>
            <select id="cab_grade" name="grade_level" required style="height:36px; border:1px solid var(--line-2); border-radius:9px; padding:0 10px; font:inherit; font-size:13px; background:#fff">
                <option value="">Select grade…</option>
                <?php foreach (grade_levels() as $g): ?>
                    <option value="<?= e($g) ?>"><?= e($g) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('plus') ?> Add cabinet</button>
        <span class="spacer"></span>
        <span class="pill pill-muted"><span class="pill-dot"></span><?= count($cabinets) ?> cabinets</span>
    </div>
</form>

<?php if (!$cabinets): ?>
    <div class="card empty" style="padding:44px">
        <span class="empty-mark"><?= icon('cabinet') ?></span>
        <strong>No cabinets yet</strong>
        <p>Add the first cabinet above, then give it shelves and folders.</p>
    </div>
<?php endif; ?>

<?php foreach ($cabinets as $cabinet):
    $shelves = $shelvesByCabinet[(int) $cabinet['id']] ?? [];
    $shelfPct = (int) round(count($shelves) / max(1, (int) $cabinet['capacity']) * 100);
    $cabinetGrade = trim((string) ($cabinet['grade_level'] ?? ''));
?>
    <section class="card" style="margin-bottom:16px">
        <div class="card-head bordered">
            <div>
                <h2 class="section-title">Cabinet <?= e($cabinet['code']) ?>
                    <?php if ($cabinetGrade !== ''): ?>
                        <span class="pill pill-ok" style="margin-left:6px"><span class="pill-dot"></span><?= e($cabinetGrade) ?></span>
                    <?php else: ?>
                        <span class="pill pill-warn" style="margin-left:6px"><span class="pill-dot"></span>Grade not set</span>
                    <?php endif; ?>
                </h2>
                <p class="section-sub"><?= count($shelves) ?> of <?= (int) $cabinet['capacity'] ?> section shelves · <?= (int) $cabinet['record_count'] ?> student folders filled</p>
            </div>
            <div style="display:flex; align-items:center; gap:12px">
                <?php if ($cabinetGrade === ''): ?>
                    <form method="post" action="/cabinets.php" style="display:flex; align-items:center; gap:6px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_cabinet_grade">
                        <input type="hidden" name="cabinet_id" value="<?= (int) $cabinet['id'] ?>">
                        <select name="grade_level" required style="height:32px; border:1px solid var(--line-2); border-radius:8px; padding:0 8px; font:inherit; font-size:12.4px; background:#fff">
                            <option value="">Grade level…</option>
                            <?php foreach (grade_levels() as $g): ?>
                                <option value="<?= e($g) ?>"><?= e($g) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-primary" type="submit">Set grade</button>
                    </form>
                <?php else: ?>
                    <div class="meter" style="width:130px">
                        <div class="meter-track"><div class="meter-fill <?= $shelfPct >= 90 ? 'danger' : ($shelfPct >= 70 ? 'warn' : '') ?>" style="width: <?= $shelfPct ?>%"></div></div>
                        <span class="meter-label"><?= $shelfPct ?>%</span>
                    </div>
                <?php endif; ?>
                <?php if (!$shelves): ?>
                    <form method="post" action="/cabinets.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_cabinet">
                        <input type="hidden" name="cabinet_id" value="<?= (int) $cabinet['id'] ?>">
                        <button class="btn btn-sm btn-ghost" type="submit" data-confirm="Remove cabinet <?= e($cabinet['code']) ?>?"><?= icon('x') ?> Remove</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div style="padding:14px 20px 18px">
            <?php if (!$shelves): ?>
                <p class="section-sub" style="margin:0 0 14px"><?= $cabinetGrade !== '' ? 'This cabinet is empty. Add its first section shelf below.' : 'Set the grade level for this cabinet before adding shelves.' ?></p>
            <?php endif; ?>

            <?php foreach ($shelves as $shelf):
                $folders = $foldersByShelf[(int) $shelf['id']] ?? [];
                $reserved = trim((string) ($shelf['section'] ?? ''));
            ?>
                <div class="shelf-block">
                    <div class="shelf-title">
                        <strong><?= e($shelf['code']) ?></strong>
                        <?php if ($reserved !== ''): ?>
                            <span class="pill pill-ok"><span class="pill-dot"></span><?= e($reserved) ?></span>
                            <?php if ($shelf['strand']): ?><span class="sub"><?= e($shelf['grade_level']) ?> · <?= e($shelf['strand']) ?></span><?php endif; ?>
                        <?php else: ?>
                            <span class="pill pill-warn"><span class="pill-dot"></span>Mixed sections</span>
                        <?php endif; ?>
                        <span class="sub"><?= count($folders) ?>/<?= (int) $shelf['capacity'] ?> folders · <?= (int) $shelf['record_count'] ?> students filed</span>
                        <span class="spacer" style="flex:1"></span>
                        <?php if (!$folders): ?>
                            <form method="post" action="/cabinets.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_shelf">
                                <input type="hidden" name="shelf_id" value="<?= (int) $shelf['id'] ?>">
                                <button class="btn btn-sm btn-ghost" type="submit" data-confirm="Remove <?= e($shelf['code']) ?>?"><?= icon('x') ?> Remove</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="shelf-folders">
                        <?php foreach ($folders as $folder):
                            $occupant = trim((string) ($folder['occupant'] ?? ''));
                        ?>
                            <span class="folder-chip<?= $occupant !== '' ? ' is-taken' : ' is-empty' ?>">
                                <span>Folder <?= e($folder['number']) ?> <small><?= $occupant !== '' ? e($occupant) : 'Empty' ?></small></span>
                                <?php if ($occupant === ''): ?>
                                    <form method="post" action="/cabinets.php" style="display:inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_folder">
                                        <input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>">
                                        <button class="chip-x" type="submit" title="Remove empty folder" data-confirm="Remove folder <?= e($folder['number']) ?>?">×</button>
                                    </form>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>

                        <?php if (count($folders) < (int) $shelf['capacity']): ?>
                            <form method="post" action="/cabinets.php" class="folder-add">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_folder">
                                <input type="hidden" name="shelf_id" value="<?= (int) $shelf['id'] ?>">
                                <button class="btn btn-sm btn-ghost" type="submit"><?= icon('plus') ?> Empty folder <?= e(storage_next_folder_no((int) $shelf['id'])) ?></button>
                            </form>
                        <?php else: ?>
                            <span class="pill pill-warn"><span class="pill-dot"></span>Shelf full</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($cabinetGrade === ''): ?>
                <p class="section-sub" style="margin:12px 0 0">Choose the grade level above to start adding section shelves.</p>
            <?php elseif (count($shelves) < (int) $cabinet['capacity']): ?>
                <form method="post" action="/cabinets.php" class="storage-add-shelf">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_shelf">
                    <input type="hidden" name="cabinet_id" value="<?= (int) $cabinet['id'] ?>">
                    <span class="sub" style="font-weight:600"><?= e(storage_next_shelf_code((int) $cabinet['id'])) ?></span>
                    <span class="pill pill-muted"><span class="pill-dot"></span><?= e($cabinetGrade) ?></span>
                    <input type="text" name="strand" list="storage-strands" maxlength="40" placeholder="Strand (SHS)">
                    <input type="text" name="section" list="storage-sections" maxlength="60" placeholder="Section name" required>
                    <button class="btn btn-sm btn-primary" type="submit"><?= icon('plus') ?> Add section shelf</button>
                </form>
            <?php else: ?>
                <p class="section-sub" style="margin:12px 0 0">Cabinet is full — add another cabinet above to keep filing records.</p>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>

<datalist id="storage-sections">
    <?php foreach ($sections as $s): ?>
        <option value="<?= e($s['section']) ?>"><?= e($s['grade_level'] . ($s['strand'] ? ' · ' . $s['strand'] : '')) ?></option>
    <?php endforeach; ?>
</datalist>
<datalist id="storage-strands">
    <?php foreach ($strandChoices as $st): ?>
        <option value="<?= e($st) ?>"></option>
    <?php endforeach; ?>
</datalist>
<?php
app_footer();
