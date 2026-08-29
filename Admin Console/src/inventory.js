function filterTable(){
    var searchEl=document.getElementById('searchInp');
    var catEl=document.getElementById('catSel');
    var q=searchEl?searchEl.value.toLowerCase():'';
    var cat=catEl?catEl.value:'';
    document.querySelectorAll('#invTable tbody tr').forEach(function(tr){
        var name=tr.dataset.name,sku=tr.dataset.sku,c=tr.dataset.cat;
        if(name===undefined)return;
        var show=((!q||(name.includes(q)||sku.includes(q)))&&(!cat||c===cat));
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