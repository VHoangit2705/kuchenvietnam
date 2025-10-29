<?php
/***************************************************
 * KUCHEN — Quản lý Sản phẩm (phiên bản FULL)
 * Cập nhật: 29.10.2025
 ***************************************************/
 include 'auth.php';
session_start();
if (!isset($_SESSION['full_name'])) {
    header("Location: index.php");
    exit();
}
include 'config.php';

/* ============== HÀM PHỤ TRỢ ============== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function post($k,$d=null){return $_POST[$k]??$d;}
function iget($k,$d=null){return $_GET[$k]??$d;}
function to_int($v){return is_numeric($v)?(int)$v:0;}
function to_float($v){return is_numeric($v)?(float)$v:0.0;}
function to_str($v){return trim((string)$v);}
function redirect_self(){
  $qs=$_GET;unset($qs['action'],$qs['id']);
  $url=strtok($_SERVER['REQUEST_URI'],'?');
  if($qs)$url.='?'.http_build_query($qs);
  header("Location:$url");exit;
}

/* ============== ACTIONS ============== */
$action=iget('action');

if($_SERVER['REQUEST_METHOD']==='POST'){
  // --- THÊM MỚI ---
  if($action==='add'){
    $cols=[
      'product_name','price','price_retail','name','exp','month','model','snnew',
      'Maketoan','Maketoantmdt','image','reminder_time','view','print','check_seri','install',
      'nhap_tay','nhap_tay_hanoi','nhap_tay_vinh','nhap_tay_hcm',
      'khoa_tem','khoa_tem_hanoi','khoa_tem_vinh','khoa_tem_hcm'
    ];
    $placeholders=rtrim(str_repeat('?,',count($cols)),',');
    $sql="INSERT INTO products (".implode(',',$cols).") VALUES ($placeholders)";
    $stmt=$conn->prepare($sql);
    $params=[];foreach($cols as $c){$params[]=post($c);}
    $types="sddssssssssiiiii"."iiiiiiii";
    $stmt->bind_param($types,...$params);
    $stmt->execute(); redirect_self();
  }

  // --- SỬA ---
  elseif($action==='edit'){
    $id=to_int(post('id'));
    $cols=[
      'product_name','price','price_retail','name','exp','month','model','snnew',
      'Maketoan','Maketoantmdt','image','reminder_time','view','print','check_seri','install',
      'nhap_tay','nhap_tay_hanoi','nhap_tay_vinh','nhap_tay_hcm',
      'khoa_tem','khoa_tem_hanoi','khoa_tem_vinh','khoa_tem_hcm'
    ];
    $sets=implode('=?,',$cols).'=?';
    $sql="UPDATE products SET $sets WHERE id=?";
    $stmt=$conn->prepare($sql);
    $params=[];foreach($cols as $c){$params[]=post($c);} $params[]=$id;
    $types="sddssssssssiiiii"."iiiiiiii"."i";
    $stmt->bind_param($types,...$params);
    $stmt->execute(); redirect_self();
  }

  // --- XÓA ---
  elseif($action==='delete'){
    $id=to_int(post('id'));
    $stmt=$conn->prepare("DELETE FROM products WHERE id=?");
    $stmt->bind_param("i",$id);
    $stmt->execute(); redirect_self();
  }

  // --- TOGGLE ---
  elseif($action==='toggle'){
    $id=to_int(post('id'));
    $col=preg_replace('/[^a-zA-Z0-9_]/','',(string)post('field'));
    $allow=[
      'print','nhap_tay','nhap_tay_hanoi','nhap_tay_vinh','nhap_tay_hcm',
      'khoa_tem','khoa_tem_hanoi','khoa_tem_vinh','khoa_tem_hcm',
      'view','check_seri','install'
    ];
    if(!in_array($col,$allow,true))redirect_self();
    $stmt=$conn->prepare("UPDATE products SET $col = IF($col=1,0,1) WHERE id=?");
    $stmt->bind_param("i",$id);
    $stmt->execute(); redirect_self();
  }
}

/* ============== LẤY DANH SÁCH ============== */
$kw=to_str(iget('q',''));
$viewFilter=to_int(iget('view_filter',0));
$page=max(1,to_int(iget('page',1)));
$perPage=50;
$offset=($page-1)*$perPage;

$where="1=1";$params=[];$types='';
if($kw!==''){
  $where.=" AND (product_name LIKE CONCAT('%',?,'%') OR name LIKE CONCAT('%',?,'%') OR model LIKE CONCAT('%',?,'%'))";
  $types.='sss';$params[]=$kw;$params[]=$kw;$params[]=$kw;
}
if($viewFilter>0){
  $where.=" AND view=?";
  $types.='i';$params[]=$viewFilter;
}

/* Ưu tiên hiển thị theo view */
$orderBy="CASE view 
  WHEN 1 THEN 1 
  WHEN 3 THEN 2 
  WHEN 2 THEN 3 
  WHEN 4 THEN 4 
  ELSE 5 END ASC, id DESC";

$sql="SELECT * FROM products WHERE $where ORDER BY $orderBy LIMIT ? OFFSET ?";
$types.='ii';$params[]=$perPage;$params[]=$offset;
$stmt=$conn->prepare($sql);
$stmt->bind_param($types,...$params);
$stmt->execute();
$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* Đếm tổng */
$cstmt=$conn->prepare("SELECT COUNT(*) FROM products WHERE $where");
if($types!=='ii'){
  if($kw!=='' && $viewFilter>0){ $cstmt->bind_param('sssi',$kw,$kw,$kw,$viewFilter); }
  elseif($kw!==''){ $cstmt->bind_param('sss',$kw,$kw,$kw); }
  elseif($viewFilter>0){ $cstmt->bind_param('i',$viewFilter); }
}
$cstmt->execute();
$total=$cstmt->get_result()->fetch_row()[0];
$totalPages=ceil($total/$perPage);

/* LẤY DÒNG EDIT */
$editRow=null;
if($action==='edit' && $_SERVER['REQUEST_METHOD']!=='POST'){
  $id=to_int(iget('id'));
  $st=$conn->prepare("SELECT * FROM products WHERE id=?");
  $st->bind_param("i",$id);
  $st->execute();
  $editRow=$st->get_result()->fetch_assoc() ?: null;
}
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>Quản lý Sản phẩm — KUCHEN</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<style>
body{background:#f9fafc;}
.table-responsive{max-height:65vh;}
.sticky-actions{position:sticky;top:0;z-index:5;background:#fff;padding:.75rem 1rem;border-bottom:1px solid #eee;}
.text-truncate-1{max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.toggle-form{display:inline;}
.pagination a{margin:0 2px;}
.small-cell{white-space:nowrap;}
</style>
</head>
<body>
<div class="container-fluid py-3">

  <div class="sticky-actions d-flex flex-wrap align-items-center">
    <h4 class="mr-3 my-1">Quản lý Sản phẩm</h4>
    <form class="form-inline" method="get">
      <input type="text" class="form-control mr-2" name="q" value="<?=h($kw)?>" placeholder="🔍 Tìm tên / alias / model...">
      <select name="view_filter" class="form-control mr-2">
        <option value="0">-- Tất cả thương hiệu --</option>
        <option value="1" <?=$viewFilter==1?'selected':''?>>KUCHEN</option>
        <option value="3" <?=$viewFilter==3?'selected':''?>>HUROM</option>
        <option value="2" <?=$viewFilter==2?'selected':''?>>Phụ kiện</option>
        <option value="4" <?=$viewFilter==4?'selected':''?>>RISOLI</option>
      </select>
      <button class="btn btn-primary">Lọc</button>
      <?php if($kw!=='' || $viewFilter>0): ?><a class="btn btn-light ml-2" href="?">Bỏ lọc</a><?php endif;?>
    </form>
    <button class="btn btn-success ml-auto" data-toggle="modal" data-target="#modalAdd">+ Thêm sản phẩm</button>
  </div>

  <div class="table-responsive bg-white shadow-sm rounded p-2 mt-2">
    <table class="table table-sm table-hover table-bordered mb-0">
      <thead class="thead-light text-center">
        <tr>
          <th>ID</th>
          <th style="min-width:260px">Sản phẩm</th>
          <th>Model</th>
          <th>Giá</th>
          <th>Retail</th>
          <th>Khóa tem<br>(All/HNI/VIN/HCM)</th>
          <th>Nhập tay<br>(All/HNI/VIN/HCM)</th>
          <th>In tem</th>
          <th>View<br>(Thương hiệu)</th>
          <th>Check SN</th>
          <th>Install</th>
          <th>Thao tác</th>
        </tr>
      </thead>
      <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="12" class="text-center text-muted">Không có dữ liệu.</td></tr>
        <?php endif;?>
        <?php foreach($rows as $r): 
          $brand=['1'=>'KUCHEN','2'=>'Phụ kiện','3'=>'HUROM','4'=>'RISOLI'];
          $brandColor=['1'=>'success','2'=>'info','3'=>'warning','4'=>'secondary'];
        ?>
        <tr>
          <td class="text-center"><?= (int)$r['id'] ?></td>
          <td>
            <div class="text-truncate-1 font-weight-bold"><?=h($r['product_name'])?></div>
            <div class="small text-muted">alias: <?=h($r['name'])?> | exp: <?=h($r['exp'])?> | month: <?=h($r['month'])?></div>
            <?php if($r['image']):?><a href="<?=h($r['image'])?>" target="_blank" class="small">Ảnh</a><?php endif;?>
          </td>
          <td class="text-center"><?=h($r['model'])?></td>
          <td class="text-right"><?=number_format((float)$r['price'])?></td>
          <td class="text-right"><?=number_format((float)$r['price_retail'])?></td>

          <!-- Khóa tem -->
          <td class="text-center small-cell">
            <?php foreach(['khoa_tem','khoa_tem_hanoi','khoa_tem_vinh','khoa_tem_hcm'] as $f): ?>
              <form class="toggle-form" method="post" action="?action=toggle">
                <input type="hidden" name="id" value="<?=$r['id']?>">
                <input type="hidden" name="field" value="<?=$f?>">
                <button class="btn btn-sm <?=$r[$f]?'btn-danger':'btn-outline-secondary'?>" title="<?=$f?>"><?=strtoupper($r[$f]?'ON':'OFF')?></button>
              </form>
            <?php endforeach;?>
          </td>

          <!-- Nhập tay -->
          <td class="text-center small-cell">
            <?php foreach(['nhap_tay','nhap_tay_hanoi','nhap_tay_vinh','nhap_tay_hcm'] as $f): ?>
              <form class="toggle-form" method="post" action="?action=toggle">
                <input type="hidden" name="id" value="<?=$r['id']?>">
                <input type="hidden" name="field" value="<?=$f?>">
                <button class="btn btn-sm <?=$r[$f]?'btn-info':'btn-outline-secondary'?>" title="<?=$f?>"><?=strtoupper($r[$f]?'ON':'OFF')?></button>
              </form>
            <?php endforeach;?>
          </td>

          <td class="text-center">
            <form class="toggle-form" method="post" action="?action=toggle">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <input type="hidden" name="field" value="print">
              <button class="btn btn-sm <?=$r['print']?'btn-danger':'btn-outline-success'?>" title="1=khóa in, 0=mở in"><?=$r['print']?'KHÓA':'MỞ'?></button>
            </form>
          </td>

          <td class="text-center">
            <form class="toggle-form" method="post" action="?action=toggle">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <input type="hidden" name="field" value="view">
              <button class="btn btn-sm btn-<?=$brandColor[$r['view']]??'light'?>"><?=$brand[$r['view']]??'N/A'?></button>
            </form>
          </td>

          <td class="text-center">
            <form class="toggle-form" method="post" action="?action=toggle">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <input type="hidden" name="field" value="check_seri">
              <button class="btn btn-sm <?=$r['check_seri']?'btn-warning':'btn-outline-secondary'?>"><?=$r['check_seri']?'ON':'OFF'?></button>
            </form>
          </td>
          <td class="text-center">
            <form class="toggle-form" method="post" action="?action=toggle">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <input type="hidden" name="field" value="install">
              <button class="btn btn-sm <?=$r['install']?'btn-dark':'btn-outline-secondary'?>"><?=$r['install']?'ON':'OFF'?></button>
            </form>
          </td>
          <td class="text-center">
            <a class="btn btn-sm btn-outline-primary mb-1" href="?action=edit&id=<?=$r['id']?>">Sửa</a>
            <form method="post" action="?action=delete" onsubmit="return confirm('Xóa sản phẩm #<?=$r['id']?> ?')" style="display:inline">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button class="btn btn-sm btn-outline-danger">Xóa</button>
            </form>
          </td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
  </div>

  <!-- PHÂN TRANG -->
  <nav class="mt-3">
    <ul class="pagination justify-content-center">
      <?php for($i=1;$i<=$totalPages;$i++): ?>
        <li class="page-item <?=$i==$page?'active':''?>"><a class="page-link" href="?page=<?=$i?><?=$kw?'&q='.urlencode($kw):''?><?=$viewFilter?'&view_filter='.$viewFilter:''?>"><?=$i?></a></li>
      <?php endfor;?>
    </ul>
  </nav>
</div>

<!-- MODAL THÊM -->
<div class="modal fade" id="modalAdd" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=add">
        <div class="modal-header"><h5 class="modal-title">Thêm sản phẩm mới</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
        <div class="modal-body">
          <div class="form-row">
            <div class="form-group col-md-6"><label>Tên sản phẩm</label><input name="product_name" class="form-control" required></div>
            <div class="form-group col-md-3"><label>Giá</label><input name="price" type="number" class="form-control"></div>
            <div class="form-group col-md-3"><label>Giá Retail</label><input name="price_retail" type="number" class="form-control"></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-4"><label>Alias</label><input name="name" class="form-control"></div>
            <div class="form-group col-md-4"><label>EXP</label><input name="exp" class="form-control"></div>
            <div class="form-group col-md-4"><label>Month</label><input name="month" class="form-control"></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-4"><label>Model</label><input name="model" class="form-control"></div>
            <div class="form-group col-md-4"><label>SN Prefix</label><input name="snnew" class="form-control"></div>
            <div class="form-group col-md-4"><label>Reminder time</label><input name="reminder_time" class="form-control"></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-3"><label>Thương hiệu (View)</label><select class="form-control" name="view"><option value="1">KUCHEN</option><option value="3">HUROM</option><option value="2">Phụ kiện</option><option value="4">RISOLI</option></select></div>
            <div class="form-group col-md-3"><label>In tem</label><select class="form-control" name="print"><option value="0">Mở in phiếu</option><option value="1">Khóa in phiếu</option></select></div>
            <div class="form-group col-md-3"><label>Check SN</label><select class="form-control" name="check_seri"><option value="0">OFF</option><option value="1">ON</option></select></div>
            <div class="form-group col-md-3"><label>Install</label><select class="form-control" name="install"><option value="0">OFF</option><option value="1">ON</option></select></div>
          </div>
          <div class="form-row">
            <?php
            $flags=[
              'nhap_tay'=>'Nhập tay All','nhap_tay_hanoi'=>'Nhập tay HN','nhap_tay_vinh'=>'Nhập tay Vinh','nhap_tay_hcm'=>'Nhập tay HCM',
              'khoa_tem'=>'Khóa tem All','khoa_tem_hanoi'=>'Khóa tem HN','khoa_tem_vinh'=>'Khóa tem Vinh','khoa_tem_hcm'=>'Khóa tem HCM'
            ];
            foreach($flags as $f=>$label): ?>
            <div class="form-group col-md-3"><label><?=$label?></label><select class="form-control" name="<?=$f?>"><option value="0">OFF</option><option value="1">ON</option></select></div>
            <?php endforeach;?>
          </div>
          <div class="form-row">
            <div class="form-group col-md-4"><label>Maketoan</label><input name="Maketoan" class="form-control"></div>
            <div class="form-group col-md-4"><label>Maketoantmdt</label><input name="Maketoantmdt" class="form-control"></div>
            <div class="form-group col-md-4"><label>Ảnh URL</label><input name="image" class="form-control"></div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-success">Lưu</button><button class="btn btn-light" data-dismiss="modal">Đóng</button></div>
      </form>
    </div>
  </div>
</div>
<?php if($action==='edit' && $editRow): ?>
<!-- MODAL SỬA -->
<div class="modal fade show" id="modalEdit" tabindex="-1" role="dialog" style="display:block;background:rgba(0,0,0,.4)">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="?action=edit">
        <div class="modal-header">
          <h5 class="modal-title">Sửa sản phẩm #<?=$editRow['id']?></h5>
          <a href="?" class="close"><span>&times;</span></a>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" value="<?=$editRow['id']?>">
          <div class="form-row">
            <div class="form-group col-md-6">
              <label>Tên sản phẩm</label>
              <input name="product_name" class="form-control" value="<?=h($editRow['product_name'])?>">
            </div>
            <div class="form-group col-md-3">
              <label>Giá</label>
              <input name="price" type="number" class="form-control" value="<?=h($editRow['price'])?>">
            </div>
            <div class="form-group col-md-3">
              <label>Giá Retail</label>
              <input name="price_retail" type="number" class="form-control" value="<?=h($editRow['price_retail'])?>">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-4"><label>Alias</label><input name="name" class="form-control" value="<?=h($editRow['name'])?>"></div>
            <div class="form-group col-md-4"><label>EXP</label><input name="exp" class="form-control" value="<?=h($editRow['exp'])?>"></div>
            <div class="form-group col-md-4"><label>Month</label><input name="month" class="form-control" value="<?=h($editRow['month'])?>"></div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-4"><label>Model</label><input name="model" class="form-control" value="<?=h($editRow['model'])?>"></div>
            <div class="form-group col-md-4"><label>SN Prefix</label><input name="snnew" class="form-control" value="<?=h($editRow['snnew'])?>"></div>
            <div class="form-group col-md-4"><label>Reminder time</label><input name="reminder_time" class="form-control" value="<?=h($editRow['reminder_time'])?>"></div>
          </div>

          <div class="form-row">
            <div class="form-group col-md-3"><label>Thương hiệu (View)</label>
              <select class="form-control" name="view">
                <option value="1" <?=$editRow['view']==1?'selected':''?>>KUCHEN</option>
                <option value="3" <?=$editRow['view']==3?'selected':''?>>HUROM</option>
                <option value="2" <?=$editRow['view']==2?'selected':''?>>Phụ kiện</option>
                <option value="4" <?=$editRow['view']==4?'selected':''?>>RISOLI</option>
              </select>
            </div>
            <div class="form-group col-md-3"><label>In tem</label>
              <select class="form-control" name="print">
                <option value="0" <?=$editRow['print']==0?'selected':''?>>Mở in phiếu</option>
                <option value="1" <?=$editRow['print']==1?'selected':''?>>Khóa in phiếu</option>
              </select>
            </div>
            <div class="form-group col-md-3"><label>Check SN</label>
              <select class="form-control" name="check_seri">
                <option value="0" <?=$editRow['check_seri']==0?'selected':''?>>OFF</option>
                <option value="1" <?=$editRow['check_seri']==1?'selected':''?>>ON</option>
              </select>
            </div>
            <div class="form-group col-md-3"><label>Install</label>
              <select class="form-control" name="install">
                <option value="0" <?=$editRow['install']==0?'selected':''?>>OFF</option>
                <option value="1" <?=$editRow['install']==1?'selected':''?>>ON</option>
              </select>
            </div>
          </div>

          <div class="form-row">
            <?php
            $flags=[
              'nhap_tay'=>'Nhập tay All','nhap_tay_hanoi'=>'Nhập tay HN','nhap_tay_vinh'=>'Nhập tay Vinh','nhap_tay_hcm'=>'Nhập tay HCM',
              'khoa_tem'=>'Khóa tem All','khoa_tem_hanoi'=>'Khóa tem HN','khoa_tem_vinh'=>'Khóa tem Vinh','khoa_tem_hcm'=>'Khóa tem HCM'
            ];
            foreach($flags as $f=>$label): ?>
            <div class="form-group col-md-3">
              <label><?=$label?></label>
              <select class="form-control" name="<?=$f?>">
                <option value="0" <?=$editRow[$f]==0?'selected':''?>>OFF</option>
                <option value="1" <?=$editRow[$f]==1?'selected':''?>>ON</option>
              </select>
            </div>
            <?php endforeach;?>
          </div>

          <div class="form-row">
            <div class="form-group col-md-4"><label>Maketoan</label><input name="Maketoan" class="form-control" value="<?=h($editRow['Maketoan'])?>"></div>
            <div class="form-group col-md-4"><label>Maketoantmdt</label><input name="Maketoantmdt" class="form-control" value="<?=h($editRow['Maketoantmdt'])?>"></div>
            <div class="form-group col-md-4"><label>Ảnh URL</label><input name="image" class="form-control" value="<?=h($editRow['image'])?>"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-primary">Lưu thay đổi</button>
          <a href="?" class="btn btn-light">Đóng</a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.4.4/dist/umd/popper.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
