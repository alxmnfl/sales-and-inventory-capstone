function filterTable(){
    var q=document.getElementById('searchInp').value.toLowerCase();
    var br=document.getElementById('branchSel').value.toUpperCase();
    var cat=document.getElementById('catSel').value;
    document.querySelectorAll('#invTable tbody tr').forEach(function(tr){
        var name=tr.dataset.name,sku=tr.dataset.sku,b=tr.dataset.branch,c=tr.dataset.cat;
        var show=((!q||(name.includes(q)||sku.includes(q)))&&(!br||b===br)&&(!cat||c===cat));
        tr.style.display=show?'':'none';
    });
}
function openAddModal(){document.getElementById('addModal').classList.add('open');}
function openEditModal(p){
    document.getElementById('editId').value=p.id;
    document.getElementById('editName').value=p.name;
    document.getElementById('editCat').value=p.category;
    document.getElementById('editBranch').value=p.branch.toUpperCase();
    document.getElementById('editPrice').value=p.price;
    document.getElementById('editStock').value=p.stock;
    document.getElementById('editModal').classList.add('open');
}
function closeModal(id){document.getElementById(id).classList.remove('open');}
function confirmDelete(id,name){
    if(!confirm('Delete "'+name+'"? This cannot be undone.'))return;
    document.getElementById('deleteId').value=id;
    document.getElementById('deleteForm').submit();
}
document.querySelectorAll('.modal-bg').forEach(function(m){
    m.addEventListener('click',function(e){if(e.target===m)m.classList.remove('open');});
});