<?php
// Rendered inside the Plugins tab by plugins.php when $activePlugin === 'schedule'.
// Do not require directly from index.php.

function _admin_load_courses(): array {
    if (!defined('COURSES_FILE') || !file_exists(COURSES_FILE)) return [];
    $raw = json_decode(file_get_contents(COURSES_FILE), true);
    return is_array($raw) ? $raw : [];
}

$scheduleAction = $_GET['action'] ?? 'list';
if (!in_array($scheduleAction, ['list', 'add', 'edit'], true)) $scheduleAction = 'list';
$editId = (int)($_GET['id'] ?? 0);

$courses    = _admin_load_courses();
$editCourse = null;

if ($scheduleAction === 'edit' && $editId > 0) {
    foreach ($courses as $c) {
        if ((int)($c['id'] ?? 0) === $editId) { $editCourse = $c; break; }
    }
    if (!$editCourse) $scheduleAction = 'list';
}
?>

<div class="admin-section">

<?php if ($scheduleAction === 'list'): ?>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
        <h2 class="section-title" style="margin:0;">Course Schedule</h2>
        <a href="?tab=plugins&plugin=schedule&action=add" class="btn btn-small">+ Add Course</a>
    </div>

    <p style="color:#6b7280;font-size:.85rem;margin-bottom:16px;">
        Use <code>[course_schedule type="All"]</code> or <code>[course_schedule type="PMP Certification"]</code> in any Custom HTML block to display the full filterable table.
        Use <code>[course_card type="PMP Certification" tab="1"]</code> for the compact sidebar widget (tab=1 Live-Virtual, tab=2 On-Demand).
    </p>

    <?php if (empty($courses)): ?>
        <p style="color:#6b7280;font-style:italic;padding:24px 0;">No courses yet. Click "+ Add Course" to get started.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:8px 12px;text-align:left;font-weight:600;white-space:nowrap;">Course Type</th>
                        <th style="padding:8px 12px;text-align:left;font-weight:600;white-space:nowrap;">Delivery</th>
                        <th style="padding:8px 12px;text-align:left;font-weight:600;white-space:nowrap;">Dates</th>
                        <th style="padding:8px 12px;text-align:left;font-weight:600;white-space:nowrap;">Time (EST)</th>
                        <th style="padding:8px 12px;text-align:left;font-weight:600;white-space:nowrap;">Price</th>
                        <th style="padding:8px 12px;text-align:center;font-weight:600;">Guar.</th>
                        <th style="padding:8px 12px;text-align:left;font-weight:600;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $c): ?>
                    <tr style="border-bottom:1px solid #e2e8f0;">
                        <td style="padding:8px 12px;"><?= h($c['course_type'] ?? '') ?></td>
                        <td style="padding:8px 12px;"><?= h($c['delivery'] ?? '') ?></td>
                        <td style="padding:8px 12px;white-space:nowrap;"><?= h($c['dates'] ?? '') ?></td>
                        <td style="padding:8px 12px;white-space:nowrap;"><?= h($c['time_est'] ?? '') ?></td>
                        <td style="padding:8px 12px;white-space:nowrap;">
                            <?php if (($c['old_price'] ?? 0) > 0): ?><s style="color:#9ca3af;font-size:.8rem;margin-right:4px;">$<?= number_format((float)$c['old_price'], 0) ?></s><?php endif; ?>
                            <?php if (($c['price'] ?? 0) > 0): ?>$<?= number_format((float)$c['price'], 0) ?><?php endif; ?>
                        </td>
                        <td style="padding:8px 12px;text-align:center;"><?= !empty($c['guaranteed']) ? '✓' : '' ?></td>
                        <td style="padding:8px 12px;white-space:nowrap;">
                            <a href="?tab=plugins&plugin=schedule&action=edit&id=<?= (int)$c['id'] ?>" style="color:#2563eb;text-decoration:none;margin-right:10px;">Edit</a>
                            <form method="post" action="schedule_save.php" style="display:inline;" onsubmit="return confirm('Delete this course?');">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:.875rem;padding:0;margin-right:10px;">Delete</button>
                            </form>
                            <form method="post" action="schedule_save.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="duplicate">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" style="background:none;border:none;color:#7c3aed;cursor:pointer;font-size:.875rem;padding:0;">Copy</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php else: /* add or edit */ ?>

    <?php
    $editTimeHour = 8;  $editTimeMin = '00'; $editTimeAmpm = 'am';
    $editEndHour  = 5;  $editEndMin  = '00'; $editEndAmpm  = 'pm';
    $editSelfPaced = false;
    if ($editCourse) {
        $te = $editCourse['time_est'] ?? '';
        $tl = strtolower(trim($te));
        if ($tl === 'self-paced' || $tl === 'anytime' || $tl === 'on-demand') {
            $editSelfPaced = true;
        } elseif (preg_match('/^(\d{1,2}):(\d{2})(am|pm)-(\d{1,2}):(\d{2})(am|pm)$/i', $te, $mt)) {
            $editTimeHour = (int)$mt[1];
            $editTimeMin  = str_pad($mt[2], 2, '0', STR_PAD_LEFT);
            $editTimeAmpm = strtolower($mt[3]);
            $editEndHour  = (int)$mt[4];
            $editEndMin   = str_pad($mt[5], 2, '0', STR_PAD_LEFT);
            $editEndAmpm  = strtolower($mt[6]);
        }
    }
    ?>

    <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
        <a href="?tab=plugins&plugin=schedule" style="color:#6b7280;text-decoration:none;font-size:.875rem;">&larr; Back to list</a>
        <h2 class="section-title" style="margin:0;"><?= $scheduleAction === 'add' ? 'Add Course' : 'Edit Course' ?></h2>
    </div>

    <form method="post" action="schedule_save.php">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editCourse ? (int)$editCourse['id'] : 0 ?>">
        <input type="hidden" name="redirect_tab" value="schedule">

        <div class="form-group">
            <label>Course Type *</label>
            <input type="text" name="course_type" value="<?= h($editCourse['course_type'] ?? '') ?>" placeholder="e.g. PMP Certification" required>
        </div>

        <div class="form-group">
            <label>Delivery</label>
            <select name="delivery">
                <option value="Live-Virtual" <?= ($editCourse['delivery'] ?? '') === 'Live-Virtual' ? 'selected' : '' ?>>Live-Virtual</option>
                <option value="On-Demand"    <?= ($editCourse['delivery'] ?? '') === 'On-Demand'    ? 'selected' : '' ?>>On-Demand</option>
            </select>
        </div>

        <div class="form-group">
            <label>Dates</label>
            <input type="text" name="dates" value="<?= h($editCourse['dates'] ?? '') ?>" placeholder="e.g. May 26, 27, 28, 29">
            <span class="hint">Enter the session dates — e.g. "May 26, 27, 28, 29"</span>
        </div>

        <div class="form-group">
            <label>Time (EST)</label>
            <label style="display:inline-flex;align-items:center;gap:6px;margin-bottom:8px;font-weight:normal;cursor:pointer;">
                <input type="checkbox" name="self_paced" id="sch-self-paced"
                    <?= $editSelfPaced ? 'checked' : '' ?>
                    onchange="document.getElementById('sch-time-fields').style.display=this.checked?'none':'flex'">
                Self-paced / On-Demand (no fixed time)
            </label>
            <div id="sch-time-fields" style="display:<?= $editSelfPaced ? 'none' : 'flex' ?>;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-size:.85rem;color:#6b7280;">Start</span>
                <select name="time_hour" style="width:auto;">
                    <?php for ($hr = 1; $hr <= 12; $hr++): ?>
                        <option value="<?= $hr ?>" <?= $hr === $editTimeHour ? 'selected' : '' ?>><?= $hr ?></option>
                    <?php endfor; ?>
                </select>
                <select name="time_min" style="width:auto;">
                    <?php foreach (['00','15','30','45'] as $mn): ?>
                        <option value="<?= $mn ?>" <?= $mn === $editTimeMin ? 'selected' : '' ?>><?= $mn ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="time_ampm" style="width:auto;">
                    <option value="am" <?= $editTimeAmpm === 'am' ? 'selected' : '' ?>>AM</option>
                    <option value="pm" <?= $editTimeAmpm === 'pm' ? 'selected' : '' ?>>PM</option>
                </select>
                <span style="font-size:.85rem;color:#6b7280;">&ndash; End</span>
                <select name="time_end_hour" style="width:auto;">
                    <?php for ($hr = 1; $hr <= 12; $hr++): ?>
                        <option value="<?= $hr ?>" <?= $hr === $editEndHour ? 'selected' : '' ?>><?= $hr ?></option>
                    <?php endfor; ?>
                </select>
                <select name="time_end_min" style="width:auto;">
                    <?php foreach (['00','15','30','45'] as $mn): ?>
                        <option value="<?= $mn ?>" <?= $mn === $editEndMin ? 'selected' : '' ?>><?= $mn ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="time_end_ampm" style="width:auto;">
                    <option value="am" <?= $editEndAmpm === 'am' ? 'selected' : '' ?>>AM</option>
                    <option value="pm" <?= $editEndAmpm === 'pm' ? 'selected' : '' ?>>PM</option>
                </select>
                <span style="font-size:.8rem;color:#9ca3af;">EST</span>
            </div>
        </div>

        <div class="form-group">
            <label>Price ($)</label>
            <input type="number" name="price" value="<?= h(number_format((float)($editCourse['price'] ?? 0), 2, '.', '')) ?>" min="0" step="0.01" style="width:180px;">
        </div>

        <div class="form-group">
            <label>Original / Crossed-Out Price ($)</label>
            <input type="number" name="old_price" value="<?= h(number_format((float)($editCourse['old_price'] ?? 0), 2, '.', '')) ?>" min="0" step="0.01" style="width:180px;">
            <span class="hint">Leave 0 to hide the strikethrough price</span>
        </div>

        <div class="form-group">
            <label>Register URL</label>
            <input type="url" name="register_url" value="<?= h($editCourse['register_url'] ?? '') ?>" placeholder="https://...">
        </div>

        <div class="form-group">
            <label>Availability Note</label>
            <input type="text" name="availability_note" value="<?= h($editCourse['availability_note'] ?? '') ?>" placeholder="e.g. 4 seats left">
            <span class="hint">Short note shown as a badge — e.g. "4 seats left", "Filling fast"</span>
        </div>

        <div class="form-group">
            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;">
                <input type="checkbox" name="guaranteed" <?= !empty($editCourse['guaranteed']) ? 'checked' : '' ?>>
                Pass Guarantee (shows a "✓ Pass Guarantee" badge)
            </label>
        </div>

        <div class="form-group">
            <label>Sort Order</label>
            <input type="number" name="sort_order" value="<?= (int)($editCourse['sort_order'] ?? 0) ?>" style="width:120px;">
            <span class="hint">Lower numbers appear first in the table</span>
        </div>

        <div style="display:flex;gap:12px;margin-top:20px;">
            <button type="submit" class="btn">Save Course</button>
            <a href="?tab=plugins&plugin=schedule" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

<?php endif; ?>

</div>
