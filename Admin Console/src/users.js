/* ── Modals ── */
function openAddModal(){
    document.getElementById('addModal').classList.add('open');
}

function openEditModal(u){
    document.getElementById('editId').value   = u.id;
    document.getElementById('editName').value = u.full_name;
    document.getElementById('editStatus').value =
        ((u.status || 'offline') === 'online') ? 'Online' : 'Offline';

    setCSelect(document.getElementById('editRoleSelect'),   u.role);
    setCSelect(document.getElementById('editBranchSelect'), (u.branch || '').toUpperCase());

    document.getElementById('editModal').classList.add('open');
}

function closeModal(id){
    var m = document.getElementById(id);
    m.classList.remove('open');
    m.querySelectorAll('.cselect.open').forEach(function(c){ c.classList.remove('open'); });
}

function confirmDelete(id, name){
    if (!confirm('Delete user "' + name + '"?')) return;
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteForm').submit();
}

document.querySelectorAll('.modal-bg').forEach(function(m){
    m.addEventListener('click', function(e){ if (e.target === m) closeModal(m.id); });
});

/* ── Custom dropdowns (Role / Branch) ── */
function setCSelect(cs, value){
    if (!cs) return;
    var hidden = cs.querySelector('input[type="hidden"]');
    var valEl  = cs.querySelector('.cselect-value');
    var target = (value || '').toUpperCase();
    var match  = null;

    cs.querySelectorAll('.cselect-list li').forEach(function(li){
        var v   = (li.getAttribute('data-value') || '').toUpperCase();
        var hit = v === target || li.textContent.trim().toUpperCase() === target;
        li.classList.toggle('selected', hit);
        if (hit) match = li;
    });

    if (match){
        hidden.value      = match.getAttribute('data-value');
        valEl.textContent = match.textContent.trim();
        valEl.classList.remove('placeholder');
    } else {
        hidden.value      = '';
        valEl.textContent = valEl.getAttribute('data-placeholder') || '— Select —';
        valEl.classList.add('placeholder');
    }
}

document.addEventListener('click', function(e){
    var trigger = e.target.closest('.cselect-trigger');
    if (trigger){
        var cs     = trigger.parentElement;
        var isOpen = cs.classList.contains('open');
        document.querySelectorAll('.cselect.open').forEach(function(c){ c.classList.remove('open'); });
        if (!isOpen) cs.classList.add('open');
        return;
    }

    var li = e.target.closest('.cselect-list li');
    if (li){
        var box = li.closest('.cselect');
        box.querySelectorAll('.cselect-list li').forEach(function(x){ x.classList.remove('selected'); });
        li.classList.add('selected');
        box.querySelector('input[type="hidden"]').value = li.getAttribute('data-value');
        var v = box.querySelector('.cselect-value');
        v.textContent = li.textContent.trim();
        v.classList.remove('placeholder');
        box.classList.remove('open');
        return;
    }

    document.querySelectorAll('.cselect.open').forEach(function(c){ c.classList.remove('open'); });
});

document.addEventListener('keydown', function(e){
    if (e.key === 'Escape'){
        document.querySelectorAll('.cselect.open').forEach(function(c){ c.classList.remove('open'); });
    }
});

/* ── Password visibility ── */
function togglePw(inputId, icon){
    var inp = document.getElementById(inputId);
    if (!inp) return;
    var reveal = inp.type === 'password';
    inp.type = reveal ? 'text' : 'password';
    icon.classList.toggle('fa-eye', !reveal);
    icon.classList.toggle('fa-eye-slash', reveal);
    icon.title = reveal ? 'Hide password' : 'Show password';
}
