<?php
require_admin();
$db = db();
$action = $_POST['action'] ?? '';
$me = current_user();

// ---- Simpan user (tambah / edit) ----
if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $nama = trim($_POST['nama'] ?? '');
    $role = in_array($_POST['role'] ?? '', ['admin','kasir','mekanik'], true) ? $_POST['role'] : 'kasir';
    $password = $_POST['password'] ?? '';
    try {
        if ($id) {
            $db->prepare("UPDATE users SET username=?, nama=?, role=? WHERE id=?")->execute([$username, $nama, $role, $id]);
            // Password hanya diganti jika kolom diisi
            if ($password !== '') {
                $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            }
            set_flash('success', 'Data pengguna diperbarui.');
        } else {
            if ($password === '') { set_flash('danger', 'Password wajib diisi untuk pengguna baru.'); header('Location: index.php?page=users'); exit; }
            $db->prepare("INSERT INTO users (username, password_hash, nama, role) VALUES (?,?,?,?)")
               ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $nama, $role]);
            set_flash('success', 'Pengguna baru ditambahkan.');
        }
    } catch (PDOException $e) {
        set_flash('danger', 'Gagal: username sudah digunakan.');
    }
    header('Location: index.php?page=users'); exit;
}

// ---- Hapus user (tidak boleh hapus diri sendiri) ----
if ($action === 'delete') {
    $id = (int)$_POST['id'];
    if ($id === (int)$me['id']) {
        set_flash('danger', 'Tidak dapat menghapus akun sendiri.');
    } else {
        $db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        set_flash('success', 'Pengguna dihapus.');
    }
    header('Location: index.php?page=users'); exit;
}

$rows = $db->query("SELECT * FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$edit = null;
if (isset($_GET['edit'])) {
    $s = $db->prepare("SELECT * FROM users WHERE id=?"); $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch(PDO::FETCH_ASSOC);
}
?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card table-card"><div class="card-body">
      <h2 class="h6"><?= $edit ? 'Edit Pengguna' : 'Tambah Pengguna' ?></h2>
      <form method="post" data-testid="user-form">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? 0 ?>">
        <div class="mb-2"><label class="form-label small">Username</label>
          <input name="username" class="form-control form-control-sm" required value="<?= esc($edit['username'] ?? '') ?>" data-testid="user-username"></div>
        <div class="mb-2"><label class="form-label small">Nama Lengkap</label>
          <input name="nama" class="form-control form-control-sm" required value="<?= esc($edit['nama'] ?? '') ?>" data-testid="user-nama"></div>
        <div class="mb-2"><label class="form-label small">Role</label>
          <select name="role" class="form-select form-select-sm" data-testid="user-role">
            <?php foreach (['admin'=>'Admin','kasir'=>'Kasir','mekanik'=>'Mekanik'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($edit['role'] ?? '')===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="mb-3"><label class="form-label small">Password <?= $edit ? '(kosongkan jika tidak diganti)' : '' ?></label>
          <input name="password" type="password" class="form-control form-control-sm" <?= $edit ? '' : 'required' ?> data-testid="user-password"></div>
        <button class="btn btn-sm btn-primary w-100" data-testid="user-submit"><?= $edit ? 'Simpan Perubahan' : 'Tambah Pengguna' ?></button>
        <?php if ($edit): ?><a href="index.php?page=users" class="btn btn-sm btn-outline-secondary w-100 mt-2">Batal</a><?php endif; ?>
      </form>
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card table-card"><div class="card-body">
      <div class="table-responsive">
      <table class="table table-sm align-middle" data-testid="users-table">
        <thead><tr><th>Username</th><th>Nama</th><th>Role</th><th>Dibuat</th><th class="text-end">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= esc($r['username']) ?><?= $r['id']==$me['id'] ? ' <span class="badge bg-primary">Anda</span>' : '' ?></td>
            <td><?= esc($r['nama']) ?></td>
            <td><span class="badge bg-<?= $r['role']==='admin'?'danger':($r['role']==='kasir'?'success':'secondary') ?>"><?= esc($r['role']) ?></span></td>
            <td class="small"><?= esc($r['created_at']) ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="index.php?page=users&edit=<?= $r['id'] ?>" data-testid="user-edit-<?= $r['id'] ?>"><i class="bi bi-pencil"></i></a>
              <?php if ($r['id'] != $me['id']): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Hapus pengguna ini?')">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" data-testid="user-delete-<?= $r['id'] ?>"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div></div>
  </div>
</div>
