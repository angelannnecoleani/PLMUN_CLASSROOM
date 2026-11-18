<?php
/**
 * assessment_attachments.php
 * Shared helpers used by save_assessment.php, update_assessment.php,
 * edit_assessment.php (and delete_assessment.php).
 * Requires $conn (mysqli) to already exist.
 */

const AA_MAX_ATTACHMENTS = 10;
const AA_MAX_BYTES       = 10485760; // 10 MB per file
const AA_IMAGE_EXT       = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
const AA_FILE_EXT        = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt'];

/* ── small utilities ─────────────────────────────────────────── */

function aa_column_exists($conn, $table, $column){
    $t = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $c = mysqli_real_escape_string($conn, $column);
    $r = mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $r && mysqli_num_rows($r) > 0;
}

/** Update optional columns only if they exist in the table (avoids SQL errors). */
function aa_update_optional($conn, $assessment_id, array $cols){
    foreach($cols as $col => $val){
        if($val === null || $val === '') continue;
        if(!aa_column_exists($conn, 'assessments', $col)) continue;
        $c = preg_replace('/[^A-Za-z0-9_]/', '', $col);
        $s = mysqli_prepare($conn, "UPDATE assessments SET `$c` = ? WHERE id = ?");
        mysqli_stmt_bind_param($s, "si", $val, $assessment_id);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
    }
}

/** "2026-10-05" + "14:30" => "2026-10-05 14:30:00" (date only if no valid time). */
function aa_combine_datetime($date, $time){
    $date = trim((string)$date);
    $time = trim((string)$time);
    if($date === '') return '';
    if($time !== '' && preg_match('/^\d{2}:\d{2}$/', $time)){
        return $date . ' ' . $time . ':00';
    }
    return $date;
}

/* ── reading ─────────────────────────────────────────────────── */

function aa_count($conn, $assessment_id){
    $s = mysqli_prepare($conn, "SELECT COUNT(*) FROM assessment_attachments WHERE assessment_id = ?");
    mysqli_stmt_bind_param($s, "i", $assessment_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $n);
    mysqli_stmt_fetch($s);
    mysqli_stmt_close($s);
    return (int)$n;
}

/** All attachments of an assessment (oldest first). */
function aa_fetch($conn, $assessment_id){
    $s = mysqli_prepare($conn,
        "SELECT id, type, file_path, original_name, url
         FROM assessment_attachments
         WHERE assessment_id = ?
         ORDER BY id ASC");
    mysqli_stmt_bind_param($s, "i", $assessment_id);
    mysqli_stmt_execute($s);
    $res = mysqli_stmt_get_result($s);
    $rows = [];
    while($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_stmt_close($s);
    return $rows;
}

/* ── writing ─────────────────────────────────────────────────── */

function aa_insert($conn, $assessment_id, $type, $path, $orig, $url){
    $s = mysqli_prepare($conn,
        "INSERT INTO assessment_attachments (assessment_id, type, file_path, original_name, url)
         VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($s, "issss", $assessment_id, $type, $path, $orig, $url);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
}

/**
 * Old assessments (created before this feature) keep their single image /
 * file / link in the assessments table. Copy those into the new table the
 * first time the assessment is edited so they show up and can be removed.
 */
function aa_ensure_migrated($conn, $assessment_id){
    if(aa_count($conn, $assessment_id) > 0) return;

    $s = mysqli_prepare($conn, "SELECT image, attachment, link FROM assessments WHERE id = ?");
    mysqli_stmt_bind_param($s, "i", $assessment_id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    mysqli_stmt_close($s);
    if(!$row) return;

    if(!empty($row['image'])){
        aa_insert($conn, $assessment_id, 'image', $row['image'], $row['image'], null);
    }
    if(!empty($row['attachment'])){
        $orig = preg_replace('/^assessment_file_[0-9a-f]{13}(?:\.[0-9]+)?_/', '', $row['attachment']);
        aa_insert($conn, $assessment_id, 'file', $row['attachment'], $orig, null);
    }
    if(!empty($row['link'])){
        aa_insert($conn, $assessment_id, 'link', null, null, $row['link']);
    }
}

/** Turn $_FILES['name'][] into a clean list. */
function aa_normalize_files($field){
    $out = [];
    if(empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) return $out;
    foreach($_FILES[$field]['name'] as $i => $name){
        if($name === '') continue;
        $out[] = [
            'name'     => $name,
            'tmp_name' => $_FILES[$field]['tmp_name'][$i],
            'error'    => $_FILES[$field]['error'][$i],
            'size'     => $_FILES[$field]['size'][$i],
        ];
    }
    return $out;
}

/**
 * Save newly posted images[], attachments[] and links[].
 * Enforces the 10-item limit (counting what is already stored).
 * Returns an array of error messages (empty = all good).
 */
function aa_save_new($conn, $assessment_id, $upload_dir){
    if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $count  = aa_count($conn, $assessment_id);
    $errors = [];

    $groups = [
        'images'      => ['image', AA_IMAGE_EXT, 'assessment_img_'],
        'attachments' => ['file',  AA_FILE_EXT,  'assessment_file_'],
    ];

    foreach($groups as $field => $cfg){
        list($type, $allowed, $prefix) = $cfg;

        foreach(aa_normalize_files($field) as $f){
            if($count >= AA_MAX_ATTACHMENTS){
                $errors[] = 'Attachment limit (' . AA_MAX_ATTACHMENTS . ') reached.';
                break 2;
            }
            if($f['error'] !== UPLOAD_ERR_OK){
                $errors[] = $f['name'] . ': upload failed.';
                continue;
            }
            if($f['size'] > AA_MAX_BYTES){
                $errors[] = $f['name'] . ': file is too large.';
                continue;
            }

            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if(!in_array($ext, $allowed, true)){
                $errors[] = $f['name'] . ': file type not allowed.';
                continue;
            }
            if($type === 'image' && !@getimagesize($f['tmp_name'])){
                $errors[] = $f['name'] . ': not a valid image.';
                continue;
            }

            $safe   = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($f['name']));
            $stored = $prefix . uniqid() . '_' . $safe;

            if(move_uploaded_file($f['tmp_name'], $upload_dir . $stored)){
                aa_insert($conn, $assessment_id, $type, $stored, basename($f['name']), null);
                $count++;
            } else {
                $errors[] = $f['name'] . ': could not be saved.';
            }
        }
    }

    // Links
    $links = $_POST['links'] ?? [];
    if(!is_array($links)) $links = [];

    $existingLinks = [];
    foreach(aa_fetch($conn, $assessment_id) as $a){
        if($a['type'] === 'link') $existingLinks[] = $a['url'];
    }

    foreach($links as $link){
        $link = trim((string)$link);
        if($link === '' || strlen($link) > 2000) continue;
        if(!preg_match('#^https?://#i', $link) || !filter_var($link, FILTER_VALIDATE_URL)) continue;
        if(in_array($link, $existingLinks, true)) continue;

        if($count >= AA_MAX_ATTACHMENTS){
            $errors[] = 'Attachment limit (' . AA_MAX_ATTACHMENTS . ') reached.';
            break;
        }
        aa_insert($conn, $assessment_id, 'link', null, null, $link);
        $existingLinks[] = $link;
        $count++;
    }

    return $errors;
}

/** Remove attachments by id (only ones that belong to this assessment) + their files. */
function aa_remove($conn, $assessment_id, $ids, $upload_dir){
    if(!is_array($ids)) return;

    foreach($ids as $rid){
        $rid = intval($rid);
        if($rid <= 0) continue;

        $s = mysqli_prepare($conn,
            "SELECT file_path FROM assessment_attachments WHERE id = ? AND assessment_id = ?");
        mysqli_stmt_bind_param($s, "ii", $rid, $assessment_id);
        mysqli_stmt_execute($s);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        mysqli_stmt_close($s);
        if(!$row) continue;

        $d = mysqli_prepare($conn,
            "DELETE FROM assessment_attachments WHERE id = ? AND assessment_id = ?");
        mysqli_stmt_bind_param($d, "ii", $rid, $assessment_id);
        mysqli_stmt_execute($d);
        mysqli_stmt_close($d);

        if(!empty($row['file_path'])){
            $p = $upload_dir . basename($row['file_path']);
            if(is_file($p)) @unlink($p);
        }
    }
}

/** Delete every attachment (rows + files). Call this from delete_assessment.php. */
function aa_delete_all($conn, $assessment_id, $upload_dir){
    aa_ensure_migrated($conn, $assessment_id);
    $ids = array_column(aa_fetch($conn, $assessment_id), 'id');
    aa_remove($conn, $assessment_id, $ids, $upload_dir);
}

/**
 * Keep the old image / attachment / link columns in sync with the first
 * image / file / link, so any page that still reads the old columns
 * (e.g. student pages) keeps working until you update it.
 */
function aa_sync_legacy($conn, $assessment_id){
    $image = null; $file = null; $link = '';

    foreach(aa_fetch($conn, $assessment_id) as $a){
        if($a['type'] === 'image' && $image === null) $image = $a['file_path'];
        if($a['type'] === 'file'  && $file  === null) $file  = $a['file_path'];
        if($a['type'] === 'link'  && $link  === '')   $link  = $a['url'];
    }

    $s = mysqli_prepare($conn, "UPDATE assessments SET image = ?, attachment = ?, link = ? WHERE id = ?");
    mysqli_stmt_bind_param($s, "sssi", $image, $file, $link, $assessment_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
}