function openAddModal(){document.getElementById('addModal').classList.add('open');}
function openEditModal(u){
    document.getElementById('editId').value=u.id;
    document.getElementById('editName').value=u.full_name;
    document.getElementById('editRole').value=u.role;
    document.getElementById('editStatus').value=(u.status||'offline')==='online'?'Online':'Offline';
    var bSel=document.getElementById('editBranch');
    var ub=(u.branch||'').toUpperCase();
    Array.from(bSel.options).forEach(function(o){if(o.value.toUpperCase()===ub||o.text.toUpperCase()===ub)o.selected=true;});
    document.getElementById('editModal').classList.add('open');
}
function closeModal(id){document.getElementById(id).classList.remove('open');}
function confirmDelete(id,name){
    if(!confirm('Delete user "'+name+'"?'))return;
    document.getElementById('deleteId').value=id;
    document.getElementById('deleteForm').submit();
}
document.querySelectorAll('.modal-bg').forEach(function(m){
    m.addEventListener('click',function(e){if(e.target===m)m.classList.remove('open');});
});