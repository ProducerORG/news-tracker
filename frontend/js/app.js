let postsCache = [];
let sourcesCache = [];
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

const setActiveButton = (id) => {
    ['showPostsButton', 'showTrashButton', 'showSourcesButton'].forEach(buttonId => {
        const btn = document.getElementById(buttonId);
        if (btn) {
            btn.classList.remove('bg-[var(--gold)]');
            btn.classList.remove('text-white');
            btn.classList.add('bg-gray-200');
        }
    });
    const activeBtn = document.getElementById(id);
    if (activeBtn) {
        activeBtn.classList.remove('bg-gray-200');
        activeBtn.classList.add('bg-[var(--gold)]', 'text-white');
    }
};

const renderPosts = () => {
    const tableBody = document.getElementById('posts-body');
    tableBody.innerHTML = '';
    postsCache.forEach(post => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td class='p-2 border'>${formatDate(post.date)}</td>
            <td class='p-2 border'>${post.source?.name || ''}</td>
            <td class='p-2 border'>${post.title}</td>
            <td class='p-2 border'>
                <a href="${post.link}" target="_blank" rel="noopener noreferrer"
                    class="text-[var(--gold)] underline hover:text-yellow-700 break-all">
                    ${post.link}
                </a>
            </td>
            <td class='p-2 border'>
                <button class='bg-[var(--gold)] hover:bg-yellow-700 text-white rounded px-2 py-1 text-xs' 
                        onclick="deletePost('${post.id}')">In Papierkorb verschieben</button>
            </td>`;
        tableBody.appendChild(row);
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

const loadPosts = async () => {
    setActiveButton('showPostsButton');
    try {
        const res = await fetch('/public/api.php?action=posts');
        const posts = await res.json();
        postsCache = posts;
        sortPosts('date');
        document.getElementById('page-title').textContent = 'Beiträge';
    } catch (error) {
        console.error('Fehler beim Laden der Beiträge:', error);
    }
};

const loadTrash = async () => {
    setActiveButton('showTrashButton');
    try {
        const res = await fetch('/public/api.php?action=posts-trash');
        const posts = await res.json();
        postsCache = posts;
        const tableBody = document.getElementById('posts-body');
        tableBody.innerHTML = '';
        posts.forEach(post => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td class='p-2 border'>${formatDate(post.date)}</td>
                <td class='p-2 border'>${post.source?.name || ''}</td>
                <td class='p-2 border'>${post.title}</td>
                <td class='p-2 border'>
                    <a href="${post.link}" target="_blank" rel="noopener noreferrer"
                       class="text-[var(--gold)] underline hover:text-yellow-700 break-all">${post.link}</a>
                </td>
                <td class='p-2 border'>
                    <button class='text-white rounded px-2 py-1 text-xs' 
                            style="background-color: #003300;"
                            onmouseover="this.style.backgroundColor='#004d00';" 
                            onmouseout="this.style.backgroundColor='#003300';"
                            onclick="restorePost('${post.id}')">
                        Wiederherstellen
                    </button>
                </td>`;
            tableBody.appendChild(row);
        });
        document.getElementById('page-title').textContent = 'Papierkorb';
    } catch (error) {
        console.error('Fehler beim Laden des Papierkorbs:', error);
    }
};

const loadSources = async () => {
    console.log('Lade Quellen...');
    setActiveButton('showSourcesButton');
    try {
        const res = await fetch('/public/api.php?action=sources');
        const sources = await res.json();
        sourcesCache = sources;
        const tableBody = document.getElementById('posts-body');
        tableBody.innerHTML = '';

        sources.forEach(source => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td class='p-2 border'>–</td>
                <td class='p-2 border'>${source.name}</td>
                <td class='p-2 border'>${source.url}</td>
                <td class='p-2 border'>${source.active ? 'Aktiv' : 'Inaktiv'}</td>
                <td class='p-2 border'>
                    <button class='bg-[var(--gold)] hover:bg-yellow-700 text-white rounded px-2 py-1 text-xs' 
                        onclick="toggleSource('${source.id}', ${!source.active})">
                        ${source.active ? 'Quelle deaktivieren' : 'Quelle aktivieren'}
                    </button>
                </td>`;
            tableBody.appendChild(row);
        });
        document.getElementById('page-title').textContent = 'Quellen';
    } catch (error) {
        console.error('Fehler beim Laden der Quellen:', error);
    }
};


const toggleSource = async (id, active) => {
    console.log('Toggle Quelle ID:', id, 'Aktiv:', active);
    try {
        const res = await fetch(`/public/api.php?action=toggle-source&id=${id}&active=${active}`, { method: 'POST' });
        console.log('Toggle Quelle Response Status:', res.status);
        loadSources();
    } catch (error) {
        console.error('Fehler beim Umschalten der Quelle:', error);
    }
};


const deletePost = async (id) => {
    try {
        const res = await fetch(`/public/api.php?action=mark-deleted&id=${id}`, { method: 'POST' });
        loadPosts();
    } catch (error) {
        console.error('Fehler beim Soft-Delete:', error);
    }
};

const restorePost = async (id) => {
    try {
        const res = await fetch(`/public/api.php?action=restore&id=${id}`, { method: 'POST' });
        loadTrash();
    } catch (error) {
        console.error('Fehler beim Wiederherstellen:', error);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    loadPosts();
    document.getElementById('showPostsButton').addEventListener('click', loadPosts);
    document.getElementById('showTrashButton').addEventListener('click', loadTrash);
    document.getElementById('showSourcesButton').addEventListener('click', loadSources);
});

window.sortPosts = sortPosts;
window.deletePost = deletePost;
window.restorePost = restorePost;
