let postsCache = [];
let sourcesCache = [];
let currentSort = { column: null, direction: 'asc' };
let currentPage = 1;
const postsPerPage = 100;
let filterTitle = '';
let filterStartDate = '';
let filterEndDate = '';
let filterSources = new Set();
let supportedArticleSources = new Set();


const formatDate = (isoString) => {
    if (!isoString) return '';
    const date = new Date(isoString);
    return date.toLocaleDateString('de-DE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit'//,
        //hour: '2-digit',
        //minute: '2-digit'
    });
};

const setActiveButton = (id) => {
    ['showPostsButton', 'showTrashButton', 'showSourcesButton'].forEach(buttonId => {
        const btn = document.getElementById(buttonId);
        if (btn) {
            btn.classList.remove('bg-[var(--gold)]', 'text-white');
            btn.classList.add('bg-gray-200', 'text-black');
        }
    });
    const activeBtn = document.getElementById(id);
    if (activeBtn) {
        activeBtn.classList.remove('bg-gray-200', 'text-black');
        activeBtn.classList.add('bg-[var(--gold)]', 'text-white');
    }
};

function showErrorNotification(message) {
    const existing = document.getElementById('error-notification');
    if (existing) existing.remove();

    const div = document.createElement('div');
    div.id = 'error-notification';
    div.className = 'fixed top-4 right-4 bg-red-600 text-white px-4 py-2 rounded shadow z-50';
    div.textContent = message;

    document.body.appendChild(div);
    setTimeout(() => div.remove(), 5000);
}


const fetchSupportedArticleSources = async () => {
    try {
        const res = await fetch('/public/api.php?action=article-sources');
        const list = await res.json();
        supportedArticleSources = new Set(list);
    } catch (err) {
        console.error('Fehler beim Abrufen der verfügbaren Artikelscraper:', err);
    }
};


const resetPostTableHead = () => {
    const tableHead = document.querySelector('thead tr');
    tableHead.innerHTML = `
        <th class="p-2 border cursor-pointer" onclick="sortPosts('date')">Datum</th>
        <th class="p-2 border cursor-pointer" onclick="sortPosts('source_id')">Quelle</th>
        <th class="p-2 border cursor-pointer" onclick="sortPosts('title')">Überschrift</th>
        <th class="p-2 border">Link</th>
        <th class="p-2 border">Kommentar</th>
        <th class="p-2 border">Umschreiben</th>
        <th class="p-2 border">Löschen</th>
    `;
};

const shortenText = (text, maxLength) => {
    if (!text) return '';
    return text.length > maxLength ? text.substring(0, maxLength) + '…' : text;
};

function renderCommentCell(post) {
    const comment = (post.comment || '').trim();
    if (!comment) {
        return `<button class='bg-[var(--gold)] hover:bg-yellow-700 text-white rounded px-2 py-1 text-xs' onclick="openCommentPopup('${post.id}', '')"> + </button>`;
    } else {
        const short = comment.length > 15 ? comment.substring(0, 15) + '…' : comment;
        return `<div class='comment-cell cursor-pointer text-sm text-gray-800 max-w-[120px] whitespace-nowrap overflow-hidden text-ellipsis border p-1 rounded' 
                    data-id="${post.id}" 
                    data-comment="${encodeURIComponent(comment)}" 
                    title="Klicken zum Bearbeiten">
                    ${short}
                </div>`;
    }
}

const renderPosts = () => {
    const tableBody = document.getElementById('posts-body');
    tableBody.innerHTML = '';

    let filteredPosts = postsCache.filter(post => {
        let matchesTitle = post.title.toLowerCase().includes(filterTitle);
        let matchesSource = filterSources.size === 0 || filterSources.has(post.source?.name);

        let matchesStart = true;
        let matchesEnd = true;
        if (filterStartDate) matchesStart = new Date(post.date) >= new Date(filterStartDate);
        if (filterEndDate) matchesEnd = new Date(post.date) <= new Date(filterEndDate);

        return matchesTitle && matchesSource && matchesStart && matchesEnd;
    });

    const totalPages = Math.ceil(filteredPosts.length / postsPerPage);
    if (currentPage > totalPages) currentPage = totalPages > 0 ? totalPages : 1;

    const startIndex = (currentPage - 1) * postsPerPage;
    const endIndex = startIndex + postsPerPage;
    const visiblePosts = filteredPosts.slice(startIndex, endIndex);

    visiblePosts.forEach(post => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td class='p-2 border'>${formatDate(post.date)}</td>
            <td class='p-2 border'>${post.source?.name || ''}</td>
            <td class='p-2 border font-bold'>${post.title}</td>
            <td class='p-2 border max-w-[300px] overflow-hidden whitespace-nowrap text-ellipsis'>
                <a href="${post.link}" target="_blank" rel="noopener noreferrer"
                    class="text-[var(--gold)] underline hover:text-yellow-700"
                    title="${post.link}">
                    ${shortenText(post.link, 50)}
                </a>
            </td>
            <td class='p-2 border text-sm text-gray-700 text-center'>${renderCommentCell(post)}</td>
            <td class='p-2 border text-sm text-gray-700 text-center'>${renderRewriteCell(post)}</td>
            <td class='p-2 border'>
                <button class='bg-[var(--gold)] hover:bg-yellow-700 text-white rounded px-2 py-1 text-xs' 
                        onclick="deletePost('${post.id}')">Löschen</button>
            </td>`;
        tableBody.appendChild(row);
    });

    renderPagination(filteredPosts.length, renderPosts);
};


const renderPagination = (totalItems, renderFunction) => {
    let pagination = document.getElementById('pagination');
    const totalPages = Math.ceil(totalItems / postsPerPage);

    if (totalPages <= 1) {
        if (pagination) pagination.remove();
        return;
    }

    if (!pagination) {
        pagination = document.createElement('div');
        pagination.id = 'pagination';
        pagination.className = 'flex gap-2 mt-4';
        document.querySelector('main').appendChild(pagination);
    }
    pagination.innerHTML = '';

    const prevButton = document.createElement('button');
    prevButton.textContent = '←';
    prevButton.disabled = currentPage === 1;
    prevButton.className = 'px-2 py-1 border rounded';
    prevButton.onclick = () => {
        currentPage--;
        renderFunction();
    };
    pagination.appendChild(prevButton);

    const nextButton = document.createElement('button');
    nextButton.textContent = '→';
    nextButton.disabled = currentPage >= totalPages;
    nextButton.className = 'px-2 py-1 border rounded';
    nextButton.onclick = () => {
        currentPage++;
        renderFunction();
    };
    pagination.appendChild(nextButton);

    const info = document.createElement('span');
    info.textContent = `Seite ${currentPage} von ${totalPages}`;
    info.className = 'px-4 py-1';
    pagination.appendChild(info);
};



const sortPosts = (column, toggle = true) => {
    if (toggle) {
        if (currentSort.column === column) {
            currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
        } else {
            currentSort = { column, direction: column === 'date' ? 'desc' : 'asc' };
        }
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

        // Sekundärsortierung nach ID (stabiler Wert)
        return a.id < b.id ? -1 : a.id > b.id ? 1 : 0;
    });
    renderPosts();
};

const loadPosts = async (resetSort = true) => {
    setActiveButton('showPostsButton');
    resetPostTableHead();
    try {
        const res = await fetch('/public/api.php?action=posts');
        const posts = await res.json();
        postsCache = posts;
        buildSourceFilterButtons();
        currentPage = 1;

        // Nur bei resetSort true oder wenn keine Sortierung vorhanden ist
        if (resetSort || !currentSort.column) {
            currentSort = { column: 'date', direction: 'desc' };
            postsCache.sort((a, b) => new Date(b.date) - new Date(a.date));
        } else {
            // Wende aktuelle Sortierung erneut an
            sortPosts(currentSort.column, false);
        }

        renderPosts();
        document.getElementById('page-title').textContent = 'Meldungen';
    } catch (error) {
        console.error('Fehler beim Laden der Meldungen:', error);
    }
};

const loadTrash = async () => {
    setActiveButton('showTrashButton');
    resetPostTableHead();
    try {
        const res = await fetch('/public/api.php?action=posts-trash');
        const posts = await res.json();
        postsCache = posts;
        buildSourceFilterButtons();
        currentPage = 1;
        if (!currentSort.column) {
            currentSort = { column: 'date', direction: 'desc' };
            postsCache.sort((a, b) => new Date(b.date) - new Date(a.date));
        } else {
            sortPosts(currentSort.column, false);
        }
        renderTrash();
        document.getElementById('page-title').textContent = 'Papierkorb';
    } catch (error) {
        console.error('Fehler beim Laden des Papierkorbs:', error);
    }
};

const renderTrash = () => {
    const tableBody = document.getElementById('posts-body');
    tableBody.innerHTML = '';

    let filteredPosts = postsCache.filter(post => {
        let matchesTitle = post.title.toLowerCase().includes(filterTitle);
        let matchesSource = filterSources.size === 0 || filterSources.has(post.source?.name);

        let matchesStart = true;
        let matchesEnd = true;
        if (filterStartDate) matchesStart = new Date(post.date) >= new Date(filterStartDate);
        if (filterEndDate) matchesEnd = new Date(post.date) <= new Date(filterEndDate);

        return matchesTitle && matchesSource && matchesStart && matchesEnd;
    });

    const totalPages = Math.ceil(filteredPosts.length / postsPerPage);
    if (currentPage > totalPages) currentPage = totalPages > 0 ? totalPages : 1;

    const startIndex = (currentPage - 1) * postsPerPage;
    const endIndex = startIndex + postsPerPage;
    const visiblePosts = filteredPosts.slice(startIndex, endIndex);

    visiblePosts.forEach(post => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td class='p-2 border'>${formatDate(post.date)}</td>
            <td class='p-2 border'>${post.source?.name || ''}</td>
            <td class='p-2 border'>${post.title}</td>
            <td class='p-2 border max-w-[300px] overflow-hidden whitespace-nowrap text-ellipsis'>
                <a href="${post.link}" target="_blank" rel="noopener noreferrer"
                    class="text-[var(--gold)] underline hover:text-yellow-700"
                    title="${post.link}">
                    ${shortenText(post.link, 50)}
                </a>
            </td>
            <td class='p-2 border text-sm text-gray-700 text-center'>${renderCommentCell(post)}</td>
            <td class='p-2 border text-sm text-gray-700 text-center'>${renderRewriteCell(post)}</td>
            <td class='p-2 border text-center'>
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

    renderPagination(filteredPosts.length, renderTrash);
};

const loadSources = async () => {
    console.log('Lade Quellen...');
    setActiveButton('showSourcesButton');
    try {
        const res = await fetch('/public/api.php?action=sources');
        const sources = await res.json();
        sourcesCache = sources;
        postsCache = sources;
        currentPage = 1;

        const tableHead = document.querySelector('thead tr');
        tableHead.innerHTML = `
            <th class="p-2 border">Quelle</th>
            <th class="p-2 border">URL</th>
            <th class="p-2 border">Status</th>
            <th class="p-2 border">Aktion</th>
        `;

        renderSources();
        document.getElementById('page-title').textContent = 'Quellen';
    } catch (error) {
        console.error('Fehler beim Laden der Quellen:', error);
    }
};


const renderSources = () => {
    const tableBody = document.getElementById('posts-body');
    tableBody.innerHTML = '';

    const totalPages = Math.ceil(postsCache.length / postsPerPage);
    if (currentPage > totalPages) currentPage = totalPages > 0 ? totalPages : 1;

    const startIndex = (currentPage - 1) * postsPerPage;
    const endIndex = startIndex + postsPerPage;
    const visibleSources = postsCache.slice(startIndex, endIndex);

    visibleSources.forEach(source => {
        const statusBadge = source.active
            ? `<span class='bg-green-600 text-white rounded px-2 py-1 text-xs'>Aktiv</span>`
            : `<span class='bg-red-600 text-white rounded px-2 py-1 text-xs'>Inaktiv</span>`;

        const row = document.createElement('tr');
        row.innerHTML = `
            <td class='p-2 border'>${source.name}</td>
            <td class='p-2 border break-all'>${source.url}</td>
            <td class='p-2 border text-center'>${statusBadge}</td>
            <td class='p-2 border text-center'>
                <button class='bg-[var(--gold)] hover:bg-yellow-700 text-white rounded px-2 py-1 text-xs' 
                    onclick="toggleSource('${source.id}', ${!source.active})">
                    ${source.active ? 'Quelle deaktivieren' : 'Quelle aktivieren'}
                </button>
            </td>`;
        tableBody.appendChild(row);
    });

    renderPagination(postsCache.length, renderSources);
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
        await loadPosts(false);
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

const buildSourceFilterButtons = () => {
    const container = document.getElementById('filter-sources');
    container.innerHTML = '';

    const uniqueSources = [...new Set(postsCache.map(post => post.source?.name).filter(Boolean))];

    uniqueSources.forEach(sourceName => {
        const button = document.createElement('button');
        button.textContent = sourceName;
        button.className = 'text-sm border rounded px-2 py-1 text-left bg-white hover:bg-gray-100 flex justify-between items-center';
        button.dataset.source = sourceName;

        const updateStyle = () => {
            if (filterSources.has(sourceName)) {
                button.style.backgroundColor = 'var(--gold)';
                button.style.color = 'white';
                button.innerHTML = `<span>${sourceName}</span><span>✓</span>`;
            } else {
                button.style.backgroundColor = 'white';
                button.style.color = 'black';
                button.innerHTML = `<span>${sourceName}</span>`;
            }
        };

        button.onclick = () => {
            if (filterSources.has(sourceName)) {
                filterSources.delete(sourceName);
            } else {
                filterSources.add(sourceName);
            }
            updateStyle();
            renderPosts();
        };

        updateStyle();
        container.appendChild(button);
    });
};

function openCommentPopup(postId, currentComment) {
    const overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';

    const container = document.createElement('div');
    container.className = 'bg-white p-6 rounded shadow-lg max-w-lg w-full';

    const textarea = document.createElement('textarea');
    textarea.value = currentComment;
    textarea.maxLength = 5000;
    textarea.className = 'w-full h-40 border p-2 text-sm mb-2';

    const counter = document.createElement('div');
    counter.className = 'text-right text-xs text-gray-500 mb-4';
    counter.textContent = `${textarea.value.length}/5000 Zeichen`;

    textarea.addEventListener('input', () => {
        counter.textContent = `${textarea.value.length}/5000 Zeichen`;
    });

    const actions = document.createElement('div');
    actions.className = 'flex justify-end gap-2';

    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = 'Verwerfen';
    cancelBtn.className = 'px-4 py-1 border rounded text-sm';
    cancelBtn.onclick = () => overlay.remove();

    const saveBtn = document.createElement('button');
    saveBtn.textContent = 'Speichern';
    saveBtn.className = 'px-4 py-1 bg-[var(--gold)] text-white rounded text-sm hover:bg-yellow-700';
    saveBtn.onclick = async () => {
        const commentText = textarea.value.trim();
        await fetch(`/public/api.php?action=update-comment&id=${postId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ comment: commentText || null })
        });
        overlay.remove();
        if (document.getElementById('showTrashButton').classList.contains('bg-[var(--gold)]')) {
            loadTrash();
        } else {
            loadPosts(false);
        }
    };

    actions.appendChild(cancelBtn);
    actions.appendChild(saveBtn);

    container.appendChild(textarea);
    container.appendChild(counter);
    container.appendChild(actions);
    overlay.appendChild(container);

    document.body.appendChild(overlay);
}


function renderRewriteCell(post) {
    const rewritten = (post.rewrittentext || '').trim();
    const sourceName = post.source?.name;
    const hasScraper = supportedArticleSources.has(sourceName);

    if (!rewritten && hasScraper) {
        return `<button id="rewrite-btn-${post.id}" class='bg-[var(--gold)] hover:bg-yellow-700 text-white rounded px-2 py-1 text-xs' onclick='triggerRewrite(${JSON.stringify(post)})'">Umschreiben</button>`;
    } else if (rewritten) {
        const short = rewritten.length > 15 ? rewritten.substring(0, 15) + '…' : rewritten;
        return `<div class='rewrite-cell cursor-pointer text-sm text-gray-800 max-w-[120px] whitespace-nowrap overflow-hidden text-ellipsis border p-1 rounded' 
                    data-id="${post.id}" 
                    data-text="${encodeURIComponent(rewritten)}" 
                    title="Klicken zum Bearbeiten">
                    ${short}
                </div>`;
    } else {
        return '';
    }
}

function openManualArticleInput(postId, url) {
    const overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';

    const container = document.createElement('div');
    container.className = 'bg-white p-6 rounded shadow-lg w-full max-w-3xl';

    const heading = document.createElement('h3');
    heading.className = 'text-[var(--gold)] text-lg font-semibold mb-1';
    heading.textContent = 'Zugriffsfehler';

    const message = document.createElement('p');
    message.className = 'mb-4 text-sm text-gray-800';
    message.textContent = 'Helfen Sie händisch nach, indem Sie den Artikel aufrufen und den Text hierher kopieren:';

    const link = document.createElement('a');
    link.href = url;
    link.textContent = url;
    link.target = '_blank';
    link.className = 'block text-blue-600 underline mb-4 break-all';

    const textarea = document.createElement('textarea');
    textarea.className = 'w-full h-60 border p-2 text-sm mb-4';
    textarea.placeholder = 'Fügen Sie den Artikeltext hier ein …';

    const actions = document.createElement('div');
    actions.className = 'flex justify-end gap-2';

    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = 'Abbrechen';
    cancelBtn.className = 'px-4 py-1 border rounded text-sm';
    cancelBtn.onclick = () => overlay.remove();

    const submitBtn = document.createElement('button');
    submitBtn.textContent = 'Absenden';
    submitBtn.className = 'px-4 py-1 bg-[var(--gold)] text-white rounded text-sm hover:bg-yellow-700';
    submitBtn.onclick = async () => {
        const manualText = textarea.value.trim();
        if (manualText.length < 100) {
            alert('Bitte geben Sie mindestens 100 Zeichen ein.');
            return;
        }

        overlay.remove();

        try {
            const res = await fetch(`/public/api.php?action=rewrite-article`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url: url, source: '', manualText })
            });
            const data = await res.json();

            if (data && data.text) {
                await fetch(`/public/api.php?action=update-rewritten&id=${postId}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ rewrittentext: data.text })
                });
                loadPosts(false);
            } else {
                throw new Error('Umschreibung fehlgeschlagen.');
            }
        } catch (err) {
            console.error('Fehler bei manueller Umschreibung:', err);
            showErrorNotification(err.message || 'Unbekannter Fehler bei manueller Umschreibung');
        }
    };

    actions.appendChild(cancelBtn);
    actions.appendChild(submitBtn);

    container.appendChild(heading);
    container.appendChild(message);
    container.appendChild(link);
    container.appendChild(textarea);
    container.appendChild(actions);

    overlay.appendChild(container);
    document.body.appendChild(overlay);
}

async function triggerRewrite(post) {
    const link = post.link;
    const source = post.source?.name || '';
    const nameText = post.source?.nametext || post.source?.name || source;

    const button = document.getElementById(`rewrite-btn-${post.id}`);
    if (button) {
        button.disabled = true;
        button.innerHTML = `<svg class="animate-spin h-4 w-4 mx-auto text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
        </svg>`;
    }
    try {
        const res = await fetch(`/public/api.php?action=rewrite-article`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url: link, source: source, nametext: nameText })
        });
        if (!res.ok) {
            const text = await res.text();
            throw new Error(`Serverfehler: ${text}`);
        }
        const data = await res.json();
        if (data && data.text) {
            await fetch(`/public/api.php?action=update-rewritten&id=${post.id}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ rewrittentext: data.text })
            });
            loadPosts(false);
        } else if (data.manualRequired && data.url) {
            openManualArticleInput(post.id, decodeURIComponent(data.url));
        } else {
            throw new Error('Kein Text erhalten');
        }
    } catch (err) {
        console.error('Fehler beim Umschreiben:', err);
        showErrorNotification(err.message || 'Unbekannter Fehler beim Umschreiben');
    } finally {
        if (button) {
            button.disabled = false;
            button.textContent = 'Umschreiben';
        }
    }
}

function openRewritePopup(postId, currentText) {
    const post = postsCache.find(p => p.id === postId);
    const originalText = (post?.articletext || '').trim();

    const overlay = document.createElement('div');
    overlay.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';

    const container = document.createElement('div');
    container.className = 'bg-white p-6 rounded shadow-lg w-full max-w-6xl';

    const contentWrapper = document.createElement('div');
    contentWrapper.className = 'flex gap-4 mb-4';

    // Read-only original article text
    const originalDiv = document.createElement('div');
    originalDiv.className = 'flex-1 flex flex-col';
    const originalLabel = document.createElement('label');
    originalLabel.className = 'text-xs font-bold mb-1';
    originalLabel.textContent = 'Originaler Artikel';
    const originalTextarea = document.createElement('textarea');
    originalTextarea.value = originalText;
    originalTextarea.readOnly = true;
    originalTextarea.className = 'w-full h-64 border p-2 text-sm bg-gray-100 resize-none';
    originalDiv.appendChild(originalLabel);
    originalDiv.appendChild(originalTextarea);

    // Editable rewritten text
    const rewriteDiv = document.createElement('div');
    rewriteDiv.className = 'flex-1 flex flex-col';
    const rewriteLabel = document.createElement('label');
    rewriteLabel.className = 'text-xs font-bold mb-1';
    rewriteLabel.textContent = 'Umschriebener Artikel';
    const rewriteTextarea = document.createElement('textarea');
    rewriteTextarea.value = currentText;
    rewriteTextarea.maxLength = 10000;
    rewriteTextarea.className = 'w-full h-64 border p-2 text-sm mb-1';
    rewriteDiv.appendChild(rewriteLabel);
    rewriteDiv.appendChild(rewriteTextarea);

    const counter = document.createElement('div');
    counter.className = 'text-right text-xs text-gray-500';
    counter.textContent = `${rewriteTextarea.value.length}/10000 Zeichen`;
    rewriteTextarea.addEventListener('input', () => {
        counter.textContent = `${rewriteTextarea.value.length}/10000 Zeichen`;
    });
    rewriteDiv.appendChild(counter);

    contentWrapper.appendChild(originalDiv);
    contentWrapper.appendChild(rewriteDiv);
    container.appendChild(contentWrapper);

    const actions = document.createElement('div');
    actions.className = 'flex justify-end gap-2';

    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = 'Verwerfen';
    cancelBtn.className = 'px-4 py-1 border rounded text-sm';
    cancelBtn.onclick = () => overlay.remove();

    const saveBtn = document.createElement('button');
    saveBtn.textContent = 'Speichern';
    saveBtn.className = 'px-4 py-1 bg-[var(--gold)] text-white rounded text-sm hover:bg-yellow-700';
    saveBtn.onclick = async () => {
        const text = rewriteTextarea.value.trim();
        await fetch(`/public/api.php?action=update-rewritten&id=${postId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rewrittentext: text || null })
        });
        overlay.remove();
        if (document.getElementById('showTrashButton').classList.contains('bg-[var(--gold)]')) {
            loadTrash();
        } else {
            loadPosts(false);
        }
    };

    actions.appendChild(cancelBtn);
    actions.appendChild(saveBtn);
    container.appendChild(actions);
    overlay.appendChild(container);

    document.body.appendChild(overlay);
}

// Eventdelegation für Rewrite-Zellen

document.addEventListener('click', (e) => {
    if (e.target.classList.contains('rewrite-cell')) {
        const id = e.target.dataset.id;
        const text = decodeURIComponent(e.target.dataset.text || '');
        openRewritePopup(id, text);
    }
});


document.addEventListener('DOMContentLoaded', async () => {
    await fetchSupportedArticleSources();
    loadPosts();

    document.getElementById('showPostsButton').addEventListener('click', loadPosts);
    document.getElementById('showTrashButton').addEventListener('click', loadTrash);
    document.getElementById('showSourcesButton').addEventListener('click', loadSources);

    document.getElementById('filter-search').addEventListener('input', e => {
        filterTitle = e.target.value.toLowerCase();
        renderPosts();
    });

    document.getElementById('filter-start-date').addEventListener('input', e => {
        filterStartDate = e.target.value;
        renderPosts();
    });

    document.getElementById('filter-end-date').addEventListener('input', e => {
        filterEndDate = e.target.value;
        renderPosts();
    });
});

document.addEventListener('click', (e) => {
    if (e.target.classList.contains('comment-cell')) {
        const id = e.target.dataset.id;
        const comment = decodeURIComponent(e.target.dataset.comment || '');
        openCommentPopup(id, comment);
    }
});


window.sortPosts = sortPosts;
window.deletePost = deletePost;
window.restorePost = restorePost;
window.openCommentPopup = openCommentPopup;
window.triggerRewrite = triggerRewrite;
window.openRewritePopup = openRewritePopup;