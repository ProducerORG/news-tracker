const loadPosts = async () => {
    const res = await fetch('/backend/public/api.php/posts');
    const posts = await res.json();
    const tableBody = document.getElementById('posts-body');
    tableBody.innerHTML = '';
    posts.forEach(post => {
        const row = document.createElement('tr');
        row.innerHTML = `<td class='p-2 border'>${post.date}</td><td class='p-2 border'>${post.source_id}</td><td class='p-2 border'>${post.title}</td><td class='p-2 border'><a href='${post.link}' target='_blank'>Link</a></td><td class='p-2 border'><button class='bg-red-500 text-white rounded p-1' onclick="deletePost('${post.id}')">Löschen</button></td>`;
        tableBody.appendChild(row);
    });
};

const deletePost = async (id) => {
    await fetch(`/backend/public/api.php/posts/${id}`, { method: 'DELETE' });
    loadPosts();
};

document.addEventListener('DOMContentLoaded', loadPosts);