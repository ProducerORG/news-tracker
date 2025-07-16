const loadPosts = async () => {
    console.log('Lade Beiträge von API...');
    try {
        const res = await fetch('/public/index.php?action=posts');
        console.log('API Response Status:', res.status);
        const posts = await res.json();
        console.log('API Response JSON:', posts);

        const tableBody = document.getElementById('posts-body');
        tableBody.innerHTML = '';

        if (!Array.isArray(posts)) {
            console.warn('Erwartetes Array von Posts, aber erhalten:', posts);
            return;
        }

        posts.forEach(post => {
            const row = document.createElement('tr');
            row.innerHTML = `<td class='p-2 border'>${post.date}</td><td class='p-2 border'>${post.source_id}</td><td class='p-2 border'>${post.title}</td><td class='p-2 border'><a href='${post.link}' target='_blank'>Link</a></td><td class='p-2 border'><button class='bg-red-500 hover:bg-red-600 text-white rounded p-1' onclick="deletePost('${post.id}')">Löschen</button></td>`;
            tableBody.appendChild(row);
        });
    } catch (error) {
        console.error('Fehler beim Laden der Beiträge:', error);
    }
};

const deletePost = async (id) => {
    console.log('Lösche Post mit ID:', id);
    try {
        const res = await fetch(`/public/index.php?action=posts&id=${id}`, { method: 'DELETE' });
        console.log('DELETE Response Status:', res.status);
        loadPosts();
    } catch (error) {
        console.error('Fehler beim Löschen des Beitrags:', error);
    }
};

document.addEventListener('DOMContentLoaded', loadPosts);