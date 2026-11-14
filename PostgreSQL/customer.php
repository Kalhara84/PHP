<?php
require __DIR__ . '/db.php';
session_start();

$USER = 'WEB';          // saved into cusucdnew / cusucdame
$PER_PAGE = 15;

// Escape text before displaying it in HTML.
function escape_html($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

// Create a token so forms can be checked before saving.
function make_token()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(16));
    }
    return bin2hex(openssl_random_pseudo_bytes(16));
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = make_token();
}

// Keep the message until the next page load.
function flash($type, $message)
{
    $_SESSION['flash'] = array($type, $message);
}

/* ---------------------------- handle POST ---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'customer.php';
    $backQuery = form_text($_POST, 'back');
    if ($backQuery !== '') {
        $back .= '?' . ltrim($backQuery, '?');
    }
    try {
        if (!hash_equals($_SESSION['csrf'], form_text($_POST, 'csrf'))) {
            throw new RuntimeException('Invalid form token, please try again.');
        }
        $action = form_text($_POST, 'action');
        $code   = strtoupper(clean_text(form_text($_POST, 'cuscus')));

        if ($action === 'add') {
            if ($code === '') {
                throw new RuntimeException('Customer code is required.');
            }
            $customer = normalise_customer($_POST);
            if ($customer['cusnam'] === '') {
                throw new RuntimeException('Customer name is required.');
            }
            if (customer_exists($code)) {
                throw new RuntimeException("Customer code '$code' already exists.");
            }
            insert_customer($code, $customer, $USER);
            flash('success', "Customer $code inserted.");
        } elseif ($action === 'edit') {
            $customer = normalise_customer($_POST);
            if ($customer['cusnam'] === '') {
                throw new RuntimeException('Customer name is required.');
            }
            if (!customer_exists($code)) {
                throw new RuntimeException("Customer code '$code' not found.");
            }
            update_customer($code, $customer, $USER);
            flash('success', "Customer $code updated.");
        } elseif ($action === 'delete') {
            if (delete_customer($code) < 1) {
                throw new RuntimeException("Customer code '$code' not found.");
            }
            flash('success', "Customer $code deleted.");
        } else {
            throw new RuntimeException('Unknown form action.');
        }
    } catch (Exception $e) {
        flash('danger', $e->getMessage());
    }
    header('Location: ' . $back);
    exit;
}

/* ----------------------------- list data ----------------------------- */
// Read the search text and page number from the URL.
$q = form_text($_GET, 'q');
$page = 1;
if (isset($_GET['page'])) {
    $page = max(1, (int)$_GET['page']);
}

$offset = ($page - 1) * $PER_PAGE;
$result = search_customers($q, $PER_PAGE, $offset);
$rows = $result[0];
$total = $result[1];
$pages = max(1, (int)ceil($total / $PER_PAGE));

// Load the data needed by the Add / Edit form.
$cats = get_sys_options(16);    // Customer categories.
$terms = get_sys_options(410);  // Credit terms.
$reps = get_reps();
$nextCode = next_customer_code();

// Display the saved success/error message once.
$flash = null;
if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
$backQs = http_build_query(array('q' => $q, 'page' => $page));

function page_url($page, $search)
{
    $params = array();
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($page > 1) {
        $params['page'] = $page;
    }
    return 'customer.php?' . http_build_query($params);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Customer Master</title>
  <link rel="stylesheet" href="assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/bootstrap-icons.min.css">
  <style>
    body { background: #f4f6fa; }
    .page-head { background: linear-gradient(135deg,#1f3a68,#2f5fa8); color:#fff; }
    .card { border: 0; box-shadow: 0 2px 10px rgba(20,40,80,.08); }
    .table thead th { font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; color:#5b6678; white-space:nowrap; }
    .table td { vertical-align: middle; }
    .code-pill { font-variant-numeric: tabular-nums; }
    .form-label { font-size:.82rem; font-weight:600; color:#4a5568; margin-bottom:.2rem; }
    .section-title { font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#2f5fa8; }
  </style>
</head>
<body>

<div class="page-head py-4 mb-4">
  <div class="container-xl d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div>
      <h1 class="h3 mb-0"><i class="bi bi-people-fill me-2"></i>Customer Master</h1>
      <div class="opacity-75 small"><?= number_format($total) ?> customer<?= $total == 1 ? '' : 's' ?><?= $q !== '' ? ' matching "' . escape_html($q) . '"' : '' ?></div>
    </div>
    <button class="btn btn-light fw-semibold" id="btnAdd"><i class="bi bi-plus-lg me-1"></i>New Customer</button>
  </div>
</div>

<div class="container-xl pb-5">

  <?php if ($flash): ?>
    <div class="alert alert-<?= escape_html($flash[0]) ?> alert-dismissible fade show" role="alert">
      <?= escape_html($flash[1]) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <form class="row g-2 mb-3" method="get" action="customer.php">
        <div class="col-md-6 col-lg-5">
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="search" name="q" value="<?= escape_html($q) ?>" class="form-control" placeholder="Search code, name, phone, e-mail, address">
            <button class="btn btn-primary">Search</button>
            <?php if ($q !== ''): ?><a href="customer.php" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
          </div>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Code</th><th>Name</th><th>Category</th><th>Address</th>
              <th>Tel</th><th>E-mail</th><th class="text-center">Status</th><th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-center text-muted py-5">No customers found.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $customer):
              $inactive = ($customer['cusactyn'] === 'N');
              $json = escape_html(json_encode($customer)); ?>
            <tr class="<?= $inactive ? 'table-secondary text-muted' : '' ?>">
              <td><span class="badge text-bg-light border code-pill"><?= escape_html($customer['cuscus']) ?></span></td>
              <td class="fw-semibold"><?= escape_html($customer['cusnam']) ?></td>
              <td><?= escape_html($customer['catname']) ?></td>
              <td><?= escape_html(trim($customer['cusad1'] . ' ' . $customer['cusad2'])) ?></td>
              <td class="text-nowrap"><?= escape_html($customer['custelno']) ?></td>
              <td><?= escape_html($customer['cusemail']) ?></td>
              <td class="text-center">
                <?= $inactive ? '<span class="badge text-bg-secondary">Inactive</span>'
                              : '<span class="badge text-bg-success">Active</span>' ?>
              </td>
              <td class="text-end text-nowrap">
                <button class="btn btn-sm btn-outline-primary btn-edit" data-row="<?= $json ?>" title="Edit"><i class="bi bi-pencil-square"></i></button>
                <button class="btn btn-sm btn-outline-danger btn-del" data-code="<?= escape_html($customer['cuscus']) ?>" data-name="<?= escape_html($customer['cusnam']) ?>" title="Delete"><i class="bi bi-trash"></i></button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($pages > 1):
          $from = max(1, $page - 2); $to = min($pages, $page + 2); ?>
        <nav class="mt-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
          <small class="text-muted">Page <?= $page ?> of <?= $pages ?></small>
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= escape_html(page_url($page - 1, $q)) ?>">&laquo;</a></li>
            <?php if ($from > 1): ?><li class="page-item"><a class="page-link" href="<?= escape_html(page_url(1, $q)) ?>">1</a></li><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
            <?php for ($i = $from; $i <= $to; $i++): ?>
              <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= escape_html(page_url($i, $q)) ?>"><?= $i ?></a></li>
            <?php endfor; ?>
            <?php if ($to < $pages): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><li class="page-item"><a class="page-link" href="<?= escape_html(page_url($pages, $q)) ?>"><?= $pages ?></a></li><?php endif; ?>
            <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= escape_html(page_url($page + 1, $q)) ?>">&raquo;</a></li>
          </ul>
        </nav>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ===================== Add / Edit modal ===================== -->
<div class="modal fade" id="custModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <form class="modal-content" method="post" action="customer.php" id="custForm" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= escape_html($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" id="f_action" value="add">
      <input type="hidden" name="back" value="<?= escape_html($backQs) ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="custModalTitle">New Customer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="section-title mb-2">General</div>
        <div class="row g-3 mb-4">
          <div class="col-md-3">
            <label class="form-label">Customer Code</label>
            <input type="text" class="form-control text-uppercase" name="cuscus" id="f_cuscus" maxlength="20" required>
          </div>
          <div class="col-md-9">
            <label class="form-label">Customer Name</label>
            <input type="text" class="form-control" name="cusnam" id="f_cusnam" maxlength="100" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Category</label>
            <select class="form-select" name="cuscatsno" id="f_cuscatsno">
              <option value="0"></option>
              <?php foreach ($cats as $option): ?><option value="<?= escape_html($option['syssno']) ?>"><?= escape_html($option['sysdes']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Sales Rep</label>
            <select class="form-select" name="cusrepsno" id="f_cusrepsno">
              <option value="0"></option>
              <?php foreach ($reps as $option): ?><option value="<?= escape_html($option['repsno']) ?>"><?= escape_html($option['repnam']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Active Flag <span class="text-muted fw-normal">(N = inactive)</span></label>
            <input type="text" class="form-control text-uppercase" name="cusactyn" id="f_cusactyn" maxlength="1">
          </div>
        </div>

        <div class="section-title mb-2">Credit</div>
        <div class="row g-3 mb-4">
          <div class="col-md-6">
            <label class="form-label">Credit Terms</label>
            <select class="form-select" name="cuscredittmssno" id="f_cuscredittmssno">
              <option value="0"></option>
              <?php foreach ($terms as $option): ?><option value="<?= escape_html($option['syssno']) ?>"><?= escape_html($option['sysdes']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Credit Limit</label>
            <input type="number" step="1" min="0" class="form-control" name="cuscreditlimit" id="f_cuscreditlimit" maxlength="12">
          </div>
        </div>

        <div class="section-title mb-2">Address &amp; Contact</div>
        <div class="row g-3 mb-4">
          <div class="col-12">
            <label class="form-label">Full Address</label>
            <input type="text" class="form-control" name="cusadd" id="f_cusadd" maxlength="200">
          </div>
          <?php for ($i = 1; $i <= 5; $i++): ?>
          <div class="col-md-6 col-lg-4">
            <label class="form-label">Address-<?= $i ?></label>
            <input type="text" class="form-control" name="cusad<?= $i ?>" id="f_cusad<?= $i ?>" maxlength="40">
          </div>
          <?php endfor; ?>
          <div class="col-md-4">
            <label class="form-label">Tel</label>
            <input type="text" class="form-control" name="custelno" id="f_custelno" maxlength="25">
          </div>
          <div class="col-md-4">
            <label class="form-label">Fax</label>
            <input type="text" class="form-control" name="cusfaxno" id="f_cusfaxno" maxlength="25">
          </div>
          <div class="col-md-4">
            <label class="form-label">E-mail</label>
            <input type="text" class="form-control" name="cusemail" id="f_cusemail" maxlength="100">
          </div>
        </div>

        <div class="section-title mb-2">Tax</div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">TIN #</label>
            <input type="text" class="form-control" name="custinno" id="f_custinno" maxlength="20">
          </div>
          <div class="col-md-4">
            <label class="form-label">VAT Reg #</label>
            <input type="text" class="form-control" name="cusvatno" id="f_cusvatno" maxlength="15">
          </div>
          <div class="col-md-4">
            <label class="form-label">SVAT Reg #</label>
            <input type="text" class="form-control" name="cussvatno" id="f_cussvatno" maxlength="25">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save</button>
      </div>
    </form>
  </div>
</div>

<!-- ===================== Delete modal ===================== -->
<div class="modal fade" id="delModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="customer.php">
      <input type="hidden" name="csrf" value="<?= escape_html($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="back" value="<?= escape_html($backQs) ?>">
      <input type="hidden" name="cuscus" id="d_code">
      <div class="modal-header">
        <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Delete customer</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        Delete <strong id="d_name"></strong> (<span id="d_code_txt"></span>)? This cannot be undone.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger">Delete</button>
      </div>
    </form>
  </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
// Open the forms and fill their fields.
var customerModal = new bootstrap.Modal(document.getElementById('custModal'));
var deleteModal = new bootstrap.Modal(document.getElementById('delModal'));
var customerForm = document.getElementById('custForm');
var modalTitle = document.getElementById('custModalTitle');
var nextCode = <?= json_encode($nextCode) ?>;

var fields = [
    'cusnam', 'cuscatsno', 'cusrepsno', 'cusactyn',
    'cuscredittmssno', 'cuscreditlimit', 'cusadd',
    'cusad1', 'cusad2', 'cusad3', 'cusad4', 'cusad5',
    'custelno', 'cusfaxno', 'cusemail', 'custinno', 'cusvatno', 'cussvatno'
];

// New Customer: clear the form and suggest the next code.
document.getElementById('btnAdd').onclick = function () {
    customerForm.reset();
    customerForm.elements['action'].value = 'add';
    customerForm.elements['cuscus'].value = nextCode;
    customerForm.elements['cuscus'].readOnly = false;
    customerForm.elements['cuscreditlimit'].value = '0';
    modalTitle.textContent = 'New Customer';
    customerModal.show();
};

// Edit: copy the selected customer's values into the form.
var editButtons = document.querySelectorAll('.btn-edit');
for (var i = 0; i < editButtons.length; i++) {
    editButtons[i].onclick = function () {
        var customer = JSON.parse(this.getAttribute('data-row'));
        customerForm.reset();
        customerForm.elements['action'].value = 'edit';
        customerForm.elements['cuscus'].value = customer.cuscus;
        customerForm.elements['cuscus'].readOnly = true;

        for (var j = 0; j < fields.length; j++) {
            var name = fields[j];
            var value = customer[name];
            if (value === null || value === undefined) {
                value = '';
            }
            if (name === 'cuscreditlimit' && value !== '') {
                value = Math.round(parseFloat(value));
            }
            customerForm.elements[name].value = value;
        }

        modalTitle.textContent = 'Edit Customer - ' + customer.cuscus;
        customerModal.show();
    };
}

// Delete: show the selected customer before submitting.
var deleteButtons = document.querySelectorAll('.btn-del');
for (var k = 0; k < deleteButtons.length; k++) {
    deleteButtons[k].onclick = function () {
        var code = this.getAttribute('data-code');
        document.getElementById('d_code').value = code;
        document.getElementById('d_code_txt').textContent = code;
        document.getElementById('d_name').textContent = this.getAttribute('data-name');
        deleteModal.show();
    };
}
</script>
</body>
</html>
