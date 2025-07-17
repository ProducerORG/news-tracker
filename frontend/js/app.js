let postsCache = [];
let currentSort = { column: null, direction: 'asc' };

const formatDate = (isoString) => {
    if (!isoString) return '';
    const date = new Date(isoString);
    return date.toLocaleDateString('de-DE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
};

const sortPosts = (column) => {
    if (currentSort.column === column) {
        currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
    } else {
        currentSort = { column, direction: 'asc' };
    }

    postsCache.sort((a, b) => {
        let aVal = a[column] || '';
        let bVal = b[column] || '';

        if (column === 'date') {
            aVal = new Date(aVal);
            bVal = new Date(bVal);
        } else {
            aVal = aVal.toString().toLowerCase();
            bVal = bVal.toString().toLowerCase();
        }

        if (aVal < bVal) return currentSort.direction === 'asc' ? -1 : 1;
        if (aVal > bVal) return currentSort.direction === 'asc' ? 1 : -1;
        return 0;
    });
    renderPosts();
};

const renderPosts = () => {
    const tableBody = document.getElementById('posts-body');
    tableBody.innerHTML = '';
    postsCache.forEach(post => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td class='p-2 border'>${formatDate(post.date)}</td>
            <td class='p-2 border'>${post.source_id}</td>
            <td class='p-2 border'>${post.title}</td>
            <td class='p-2 border'><a href='${post.link}' target='_blank'>Link</a></td>
            <td class='p-2 border'>
                <button class='bg-red-500 hover:bg-red-600 text-white rounded p-1' onclick="deletePost('${post.id}')">Löschen</button>
            </td>`;
        tableBody.appendChild(row);
    });
};

const loadPosts = async () => {
    console.log('Lade Beiträge von API...');
    try {
        const res = await fetch('/public/api.php?action=posts');
        console.log('API Response Status:', res.status);
        const posts = await res.json();
        postsCache = posts;
        sortPosts('date');
    } catch (error) {
        console.error('Fehler beim Laden der Beiträge:', error);
    }
};

const deletePost = async (id) => {
    console.log('Papierkorb Post ID:', id);
    try {
        const res = await fetch(`/public/api.php?action=mark-deleted&id=${id}`, { method: 'POST' });
        console.log('DELETE (soft) Response Status:', res.status);
        loadPosts();
    } catch (error) {
        console.error('Fehler beim Soft-Delete:', error);
    }
};

const restorePost = async (id) => {
    console.log('Wiederherstellen Post ID:', id);
    try {
        const res = await fetch(`/public/api.php?action=restore&id=${id}`, { method: 'POST' });
        console.log('Restore Response Status:', res.status);
        loadTrash();
    } catch (error) {
        console.error('Fehler beim Wiederherstellen:', error);
    }
};

const loadTrash = async () => {
    console.log('Lade Papierkorb...');
    try {
        const res = await fetch('/public/api.php?action=posts-trash');
        const posts = await res.json();
        const tableBody = document.getElementById('posts-body');
        tableBody.innerHTML = '';
        posts.forEach(post => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td class='p-2 border'>${formatDate(post.date)}</td>
                <td class='p-2 border'>${post.source_id}</td>
                <td class='p-2 border'>${post.title}</td>
                <td class='p-2 border'><a href='${post.link}' target='_blank'>Link</a></td>
                <td class='p-2 border'>
                    <button class='bg-green-500 hover:bg-green-600 text-white rounded p-1' onclick="restorePost('${post.id}')">Wiederherstellen</button>
                </td>`;
            tableBody.appendChild(row);
        });
    } catch (error) {
        console.error('Fehler beim Laden des Papierkorbs:', error);
    }
};

let showingTrash = false;

const toggleTrash = () => {
    showingTrash = !showingTrash;
    const btn = document.getElementById('toggleTrashButton');
    if (showingTrash) {
        loadTrash();
        btn.textContent = 'Beiträge ansehen';
    } else {
        loadPosts();
        btn.textContent = 'Papierkorb ansehen';
    }
};

document.addEventListener('DOMContentLoaded', loadPosts);
