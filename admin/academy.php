<?php
// ============================================================
// BraidedbyAGB — Admin Training Academy (courses + curriculum)
// FILE: /admin/academy.php
// ALL POST HANDLING BEFORE layout.php to avoid headers-sent error
//
// Manage courses, their cohorts (dated intakes with seat limits) and the
// curriculum (modules → lessons). Enrolments are managed on /admin/enrolments.
// ============================================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
$db = getDB();

$msg = ''; $error = '';

function uniqueCourseSlug(PDO $db, string $title, int $ignoreId = 0): string {
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: 'course';
    $slug = $base; $n = 1;
    while (true) {
        $s = $db->prepare("SELECT id FROM courses WHERE slug=? AND id<>?");
        $s->execute([$slug, $ignoreId]);
        if (!$s->fetch()) return $slug;
        $slug = $base . '-' . (++$n);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    try {
        if ($action === 'create_course' || $action === 'update_course') {
            $id       = (int)($_POST['id'] ?? 0);
            $title    = sanitize($_POST['title'] ?? '');
            $level    = in_array($_POST['level'] ?? '', ['beginner','intermediate','advanced'], true) ? $_POST['level'] : 'beginner';
            $summary  = sanitize($_POST['summary'] ?? '');
            $desc     = sanitize($_POST['description'] ?? '');
            $syllabus = sanitize($_POST['syllabus'] ?? '');
            $price    = max(0.0, round((float)($_POST['price'] ?? 0), 2));
            $deposit  = max(0.0, round((float)($_POST['deposit_amount'] ?? 0), 2));
            if ($title === '') { $error = 'Course title is required.'; }
            else {
                $img = sanitize($_POST['image_url'] ?? '');
                if (!empty($_FILES['image_file']['name']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                    $up = uploadImage($_FILES['image_file'], 'academy');
                    if ($up) $img = $up; else $error = 'Image upload failed — JPG/PNG/WebP max 5MB.';
                }
                if (!$error && $action === 'create_course') {
                    $slug = uniqueCourseSlug($db, $title);
                    $max  = (int)$db->query("SELECT COALESCE(MAX(display_order),0) FROM courses")->fetchColumn();
                    $db->prepare("INSERT INTO courses (title,slug,level,summary,description,syllabus,price,deposit_amount,image_url,is_active,display_order)
                                  VALUES (?,?,?,?,?,?,?,?,?,1,?)")
                       ->execute([$title,$slug,$level,$summary,$desc,$syllabus,$price,$deposit,$img,$max+1]);
                    $msg = 'Course created.';
                } elseif (!$error) {
                    $db->prepare("UPDATE courses SET title=?,level=?,summary=?,description=?,syllabus=?,price=?,deposit_amount=?,image_url=?,is_active=? WHERE id=?")
                       ->execute([$title,$level,$summary,$desc,$syllabus,$price,$deposit,$img,isset($_POST['is_active'])?1:0,$id]);
                    $msg = 'Course updated.';
                }
            }
        } elseif ($action === 'delete_course') {
            $id = (int)$_POST['id'];
            $inUse = (int)$db->prepare("SELECT COUNT(*) FROM course_enrolments WHERE course_id=?")->execute([$id])
                   ? (int)$db->query("SELECT COUNT(*) FROM course_enrolments WHERE course_id=$id")->fetchColumn() : 0;
            if ($inUse > 0) { $db->prepare("UPDATE courses SET is_active=0 WHERE id=?")->execute([$id]); $msg = 'Course has enrolments — hidden instead of deleted.'; }
            else { $db->prepare("DELETE FROM courses WHERE id=?")->execute([$id]); $msg = 'Course deleted.'; }

        } elseif ($action === 'add_cohort') {
            $cid = (int)$_POST['course_id'];
            $db->prepare("INSERT INTO course_cohorts (course_id,name,start_date,end_date,seats,location,is_active) VALUES (?,?,?,?,?,?,1)")
               ->execute([$cid, sanitize($_POST['name']),
                          $_POST['start_date'] ?: null, $_POST['end_date'] ?: null,
                          max(0,(int)$_POST['seats']), sanitize($_POST['location'] ?? '')]);
            $msg = 'Cohort added.';
        } elseif ($action === 'update_cohort') {
            $db->prepare("UPDATE course_cohorts SET name=?,start_date=?,end_date=?,seats=?,location=?,is_active=? WHERE id=?")
               ->execute([sanitize($_POST['name']), $_POST['start_date'] ?: null, $_POST['end_date'] ?: null,
                          max(0,(int)$_POST['seats']), sanitize($_POST['location'] ?? ''), isset($_POST['is_active'])?1:0, (int)$_POST['id']]);
            $msg = 'Cohort updated.';
        } elseif ($action === 'delete_cohort') {
            try { $db->prepare("DELETE FROM course_cohorts WHERE id=?")->execute([(int)$_POST['id']]); $msg = 'Cohort deleted.'; }
            catch (PDOException $e) { $db->prepare("UPDATE course_cohorts SET is_active=0 WHERE id=?")->execute([(int)$_POST['id']]); $msg = 'Cohort has enrolments — hidden instead.'; }

        } elseif ($action === 'add_module') {
            $cid = (int)$_POST['course_id'];
            $max = (int)$db->query("SELECT COALESCE(MAX(display_order),0) FROM course_modules WHERE course_id=$cid")->fetchColumn();
            $db->prepare("INSERT INTO course_modules (course_id,title,display_order) VALUES (?,?,?)")->execute([$cid, sanitize($_POST['title']), $max+1]);
            $msg = 'Module added.';
        } elseif ($action === 'delete_module') {
            $db->prepare("DELETE FROM course_modules WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'Module deleted.';
        } elseif ($action === 'add_lesson') {
            $mid = (int)$_POST['module_id'];
            $max = (int)$db->query("SELECT COALESCE(MAX(display_order),0) FROM course_lessons WHERE module_id=$mid")->fetchColumn();
            $db->prepare("INSERT INTO course_lessons (module_id,title,content,is_preview,display_order) VALUES (?,?,?,?,?)")
               ->execute([$mid, sanitize($_POST['title']), sanitize($_POST['content'] ?? ''), isset($_POST['is_preview'])?1:0, $max+1]);
            $msg = 'Lesson added.';
        } elseif ($action === 'update_lesson') {
            $db->prepare("UPDATE course_lessons SET title=?,content=?,is_preview=? WHERE id=?")
               ->execute([sanitize($_POST['title']), sanitize($_POST['content'] ?? ''), isset($_POST['is_preview'])?1:0, (int)$_POST['id']]);
            $msg = 'Lesson updated.';
        } elseif ($action === 'delete_lesson') {
            $db->prepare("DELETE FROM course_lessons WHERE id=?")->execute([(int)$_POST['id']]);
            $msg = 'Lesson deleted.';
        }
    } catch (Exception $e) {
        error_log('Academy admin error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

$pageTitle = 'Training Academy';
require_once __DIR__ . '/includes/layout.php';

try {
    $courses = $db->query("SELECT c.*,
                             (SELECT COUNT(*) FROM course_enrolments e WHERE e.course_id=c.id) AS enrol_count
                           FROM courses c ORDER BY c.is_active DESC, c.display_order ASC")->fetchAll();
    $cohortsByCourse = [];
    foreach ($db->query("SELECT co.*,
                             (SELECT COUNT(*) FROM course_enrolments e WHERE e.cohort_id=co.id AND e.status<>'withdrawn') AS taken
                           FROM course_cohorts co ORDER BY co.start_date IS NULL, co.start_date ASC, co.id ASC")->fetchAll() as $co) {
        $cohortsByCourse[$co['course_id']][] = $co;
    }
    $modulesByCourse = [];
    foreach ($db->query("SELECT * FROM course_modules ORDER BY display_order ASC, id ASC")->fetchAll() as $m) {
        $modulesByCourse[$m['course_id']][] = $m;
    }
    $lessonsByModule = [];
    foreach ($db->query("SELECT * FROM course_lessons ORDER BY display_order ASC, id ASC")->fetchAll() as $l) {
        $lessonsByModule[$l['module_id']][] = $l;
    }
} catch (Exception $e) {
    $courses = []; $cohortsByCourse = []; $modulesByCourse = []; $lessonsByModule = [];
    $error = $error ?: 'Could not load the academy. Run the database migration first.';
}

function lvlLabel(string $l): string { return ucfirst($l); }
?>

<?php if ($msg):   ?><div class="alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="page-header" style="margin-bottom:20px">
  <div>
    <h2 class="section-heading">Training Academy</h2>
    <p style="color:var(--admin-muted);font-size:0.8rem"><?= count($courses) ?> course(s) · <a href="/academy" target="_blank">/academy</a> · <a href="/admin/enrolments">Enrolments →</a></p>
  </div>
  <button class="btn-admin btn-admin-primary" onclick="document.getElementById('new-course').classList.toggle('hidden')">+ New Course</button>
</div>

<!-- Create course -->
<div id="new-course" class="hidden" style="margin-bottom:24px">
  <div class="admin-card"><div class="admin-card-body">
    <form method="POST" action="/admin/academy" enctype="multipart/form-data">
      <input type="hidden" name="action" value="create_course">
      <div class="admin-form-row" style="margin-bottom:12px">
        <div class="admin-form-group"><label class="admin-label">Title *</label><input class="admin-input" name="title" required placeholder="Beginner Braiding Course"></div>
        <div class="admin-form-group"><label class="admin-label">Level</label>
          <select class="admin-input" name="level"><option value="beginner">Beginner</option><option value="intermediate">Intermediate</option><option value="advanced">Advanced</option></select></div>
        <div class="admin-form-group"><label class="admin-label">Price (£)</label><input class="admin-input" type="number" name="price" step="0.01" min="0" value="0"></div>
        <div class="admin-form-group"><label class="admin-label">Deposit (£)</label><input class="admin-input" type="number" name="deposit_amount" step="0.01" min="0" value="0"></div>
      </div>
      <div class="admin-form-group" style="margin-bottom:12px"><label class="admin-label">Short summary (card)</label><input class="admin-input" name="summary" placeholder="One line shown on the academy page"></div>
      <div class="admin-form-row" style="margin-bottom:12px">
        <div class="admin-form-group" style="flex:1"><label class="admin-label">Description</label><textarea class="admin-input admin-textarea" name="description" rows="3"></textarea></div>
        <div class="admin-form-group" style="flex:1"><label class="admin-label">Syllabus (shown to prospects)</label><textarea class="admin-input admin-textarea" name="syllabus" rows="3"></textarea></div>
      </div>
      <div class="admin-form-group" style="margin-bottom:12px"><label class="admin-label">Course image</label><input class="admin-input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp" style="padding:6px"></div>
      <button class="btn-admin btn-admin-primary" type="submit">Create Course</button>
    </form>
  </div></div>
</div>

<?php if (empty($courses)): ?>
  <div class="table-empty"><div class="table-empty-icon">🎓</div><p>No courses yet. Create your first one above.</p></div>
<?php else: foreach ($courses as $c): $id=(int)$c['id']; $active=(int)$c['is_active']===1; ?>
  <div class="admin-card" style="margin-bottom:12px;<?= $active?'':'opacity:.65' ?>">
    <div class="admin-card-header" style="cursor:pointer" onclick="document.getElementById('c-<?= $id ?>').classList.toggle('hidden')">
      <span class="admin-card-title"><?= htmlspecialchars($c['title']) ?>
        <span class="admin-badge" style="background:#ede9fe;color:#5b21b6"><?= lvlLabel($c['level']) ?></span>
        <?php if (!$active): ?><span class="admin-badge" style="background:#6b7280">Hidden</span><?php endif; ?>
      </span>
      <span style="font-size:0.78rem;color:var(--admin-muted)">£<?= number_format((float)$c['price'],2) ?> · <?= (int)$c['enrol_count'] ?> enrolled</span>
    </div>
    <div id="c-<?= $id ?>" class="admin-card-body hidden">
      <!-- Edit course -->
      <form method="POST" action="/admin/academy" enctype="multipart/form-data" style="margin-bottom:18px">
        <input type="hidden" name="action" value="update_course"><input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="image_url" value="<?= htmlspecialchars($c['image_url'] ?? '') ?>">
        <div class="admin-form-row" style="margin-bottom:10px">
          <div class="admin-form-group"><label class="admin-label">Title</label><input class="admin-input" name="title" value="<?= htmlspecialchars($c['title']) ?>" required></div>
          <div class="admin-form-group"><label class="admin-label">Level</label>
            <select class="admin-input" name="level"><?php foreach(['beginner','intermediate','advanced'] as $lv): ?><option value="<?= $lv ?>" <?= $c['level']===$lv?'selected':'' ?>><?= lvlLabel($lv) ?></option><?php endforeach; ?></select></div>
          <div class="admin-form-group"><label class="admin-label">Price (£)</label><input class="admin-input" type="number" name="price" step="0.01" min="0" value="<?= htmlspecialchars((string)$c['price']) ?>"></div>
          <div class="admin-form-group"><label class="admin-label">Deposit (£)</label><input class="admin-input" type="number" name="deposit_amount" step="0.01" min="0" value="<?= htmlspecialchars((string)$c['deposit_amount']) ?>"></div>
        </div>
        <div class="admin-form-group" style="margin-bottom:10px"><label class="admin-label">Summary</label><input class="admin-input" name="summary" value="<?= htmlspecialchars($c['summary'] ?? '') ?>"></div>
        <div class="admin-form-row" style="margin-bottom:10px">
          <div class="admin-form-group" style="flex:1"><label class="admin-label">Description</label><textarea class="admin-input admin-textarea" name="description" rows="3"><?= htmlspecialchars($c['description'] ?? '') ?></textarea></div>
          <div class="admin-form-group" style="flex:1"><label class="admin-label">Syllabus</label><textarea class="admin-input admin-textarea" name="syllabus" rows="3"><?= htmlspecialchars($c['syllabus'] ?? '') ?></textarea></div>
        </div>
        <div class="admin-form-group" style="margin-bottom:10px"><label class="admin-label">Replace image</label><input class="admin-input" type="file" name="image_file" accept="image/jpeg,image/png,image/webp" style="padding:6px"></div>
        <label style="display:flex;gap:8px;align-items:center;margin-bottom:12px"><input type="checkbox" name="is_active" <?= $active?'checked':'' ?>> Visible on the website</label>
        <div style="display:flex;gap:8px">
          <button class="btn-admin btn-admin-primary" type="submit">Save Course</button>
          <button type="submit" form="delc-<?= $id ?>" class="btn-admin btn-admin-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5">Delete</button>
        </div>
      </form>
      <form id="delc-<?= $id ?>" method="POST" action="/admin/academy" style="display:none" onsubmit="return confirm('Delete this course?')">
        <input type="hidden" name="action" value="delete_course"><input type="hidden" name="id" value="<?= $id ?>">
      </form>

      <!-- Cohorts -->
      <h4 style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--admin-muted);margin:6px 0">Cohorts (intakes)</h4>
      <?php foreach (($cohortsByCourse[$id] ?? []) as $co): ?>
        <form method="POST" action="/admin/academy" style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;margin-bottom:6px;border:1px solid var(--admin-border);border-radius:8px;padding:8px">
          <input type="hidden" name="action" value="update_cohort"><input type="hidden" name="id" value="<?= (int)$co['id'] ?>">
          <div class="admin-form-group" style="margin:0"><label class="admin-label" style="font-size:0.68rem">Name</label><input class="admin-input" name="name" value="<?= htmlspecialchars($co['name']) ?>" style="width:150px"></div>
          <div class="admin-form-group" style="margin:0"><label class="admin-label" style="font-size:0.68rem">Start</label><input class="admin-input" type="date" name="start_date" value="<?= htmlspecialchars($co['start_date'] ?? '') ?>" style="width:150px"></div>
          <div class="admin-form-group" style="margin:0"><label class="admin-label" style="font-size:0.68rem">End</label><input class="admin-input" type="date" name="end_date" value="<?= htmlspecialchars($co['end_date'] ?? '') ?>" style="width:150px"></div>
          <div class="admin-form-group" style="margin:0"><label class="admin-label" style="font-size:0.68rem">Seats (<?= (int)$co['taken'] ?> taken)</label><input class="admin-input" type="number" name="seats" value="<?= (int)$co['seats'] ?>" min="0" style="width:90px"></div>
          <div class="admin-form-group" style="margin:0"><label class="admin-label" style="font-size:0.68rem">Location</label><input class="admin-input" name="location" value="<?= htmlspecialchars($co['location'] ?? '') ?>" style="width:140px"></div>
          <label style="display:flex;gap:4px;align-items:center;font-size:0.75rem"><input type="checkbox" name="is_active" <?= (int)$co['is_active']?'checked':'' ?>> Open</label>
          <button class="btn-admin btn-admin-sm btn-admin-primary" type="submit">Save</button>
          <button type="submit" form="delco-<?= (int)$co['id'] ?>" class="btn-admin btn-admin-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5">✕</button>
        </form>
        <form id="delco-<?= (int)$co['id'] ?>" method="POST" action="/admin/academy" style="display:none" onsubmit="return confirm('Delete this cohort?')">
          <input type="hidden" name="action" value="delete_cohort"><input type="hidden" name="id" value="<?= (int)$co['id'] ?>">
        </form>
      <?php endforeach; ?>
      <form method="POST" action="/admin/academy" style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-end;margin:6px 0 16px">
        <input type="hidden" name="action" value="add_cohort"><input type="hidden" name="course_id" value="<?= $id ?>">
        <input class="admin-input" name="name" placeholder="e.g. Spring 2026" style="width:150px" required>
        <input class="admin-input" type="date" name="start_date" style="width:150px">
        <input class="admin-input" type="date" name="end_date" style="width:150px">
        <input class="admin-input" type="number" name="seats" placeholder="Seats" value="8" min="0" style="width:90px">
        <input class="admin-input" name="location" placeholder="Location" style="width:140px">
        <button class="btn-admin btn-admin-sm btn-admin-outline" type="submit">+ Add cohort</button>
      </form>

      <!-- Curriculum -->
      <h4 style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--admin-muted);margin:6px 0">Curriculum</h4>
      <?php foreach (($modulesByCourse[$id] ?? []) as $m): $mid=(int)$m['id']; ?>
        <div style="border:1px solid var(--admin-border);border-radius:8px;padding:10px;margin-bottom:8px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
            <strong style="font-size:0.9rem"><?= htmlspecialchars($m['title']) ?></strong>
            <form method="POST" action="/admin/academy" onsubmit="return confirm('Delete module and its lessons?')" style="margin:0">
              <input type="hidden" name="action" value="delete_module"><input type="hidden" name="id" value="<?= $mid ?>">
              <button class="btn-admin btn-admin-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5" type="submit">✕ Module</button>
            </form>
          </div>
          <?php foreach (($lessonsByModule[$mid] ?? []) as $l): ?>
            <form method="POST" action="/admin/academy" style="display:flex;gap:6px;align-items:center;margin-bottom:4px">
              <input type="hidden" name="action" value="update_lesson"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
              <input class="admin-input" name="title" value="<?= htmlspecialchars($l['title']) ?>" style="flex:1">
              <label style="display:flex;gap:3px;align-items:center;font-size:0.7rem;white-space:nowrap"><input type="checkbox" name="is_preview" <?= (int)$l['is_preview']?'checked':'' ?>> preview</label>
              <button class="btn-admin btn-admin-sm btn-admin-primary" type="submit">Save</button>
              <button type="submit" form="dell-<?= (int)$l['id'] ?>" class="btn-admin btn-admin-sm" style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5">✕</button>
            </form>
            <form id="dell-<?= (int)$l['id'] ?>" method="POST" action="/admin/academy" style="display:none"><input type="hidden" name="action" value="delete_lesson"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"></form>
          <?php endforeach; ?>
          <form method="POST" action="/admin/academy" style="display:flex;gap:6px;align-items:center;margin-top:4px">
            <input type="hidden" name="action" value="add_lesson"><input type="hidden" name="module_id" value="<?= $mid ?>">
            <input class="admin-input" name="title" placeholder="New lesson title" style="flex:1" required>
            <label style="display:flex;gap:3px;align-items:center;font-size:0.7rem"><input type="checkbox" name="is_preview"> preview</label>
            <button class="btn-admin btn-admin-sm btn-admin-outline" type="submit">+ Lesson</button>
          </form>
        </div>
      <?php endforeach; ?>
      <form method="POST" action="/admin/academy" style="display:flex;gap:6px;align-items:center">
        <input type="hidden" name="action" value="add_module"><input type="hidden" name="course_id" value="<?= $id ?>">
        <input class="admin-input" name="title" placeholder="New module title" style="flex:1" required>
        <button class="btn-admin btn-admin-sm btn-admin-outline" type="submit">+ Module</button>
      </form>
    </div>
  </div>
<?php endforeach; endif; ?>

<style>#new-course.hidden,[id^="c-"].hidden{display:none}</style>

<?php require_once __DIR__ . '/includes/layout-end.php'; ?>
