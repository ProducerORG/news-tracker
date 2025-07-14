const loadPosts = async () => {
    const res = await fetch('/posts');
    const posts = await res.json();
    const tableBody = document.getElementById('posts-body');
    tableBody.innerHTML = '';
    posts.forEach(post => {
        const row = document.createElement('tr');
        row.innerHTML = `<td>${post.date}</td><td>${post.source_id}</td><td>${post.title}</td><td><a href='${post.link}'>Link</a></td><td><button onclick="deletePost('${post.id}')">🗑</button></td>`;
        tableBody.appendChild(row);
    });
};

const deletePost = async (id) => {
    await fetch(`/posts/${id}`, { method: 'DELETE' });
    loadPosts();
};

document.addEventListener('DOMContentLoaded', loadPosts);