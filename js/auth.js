// ==================== AUTHENTICATION SYSTEM ====================

// Demo user database (in production, this would be server-side)
const DEMO_USERS = {
    'admin': {
        password: 'password123',
        role: 'admin',
        name: 'AJ Delgado',
        email: 'admin@ksyn.com'
    },
    'user': {
        password: 'password123',
        role: 'user',
        name: 'John Doe',
        email: 'user@ksyn.com'
    }
};

// Pages that do NOT require login
const PUBLIC_PAGES = ['login.html', 'user-register.html'];

// Check if user is logged in on protected pages
function checkAuthentication() {
    const currentUser = getCurrentUser();

    if (!currentUser) {
        window.location.href = 'login.html';
        return false;
    }

    return true;
}

// Get current user from session storage
function getCurrentUser() {
    const userJSON = sessionStorage.getItem('currentUser');
    return userJSON ? JSON.parse(userJSON) : null;
}

// Login handler
function handleLogin(event) {
    event.preventDefault();

    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;
    const role = document.getElementById('role').value;

    if (!username || !password) {
        showError('Please enter both username and password');
        return;
    }

    if (DEMO_USERS[username]) {
        const user = DEMO_USERS[username];

        if (user.password === password && user.role === role) {
            const userData = {
                username: username,
                role: user.role,
                name: user.name,
                email: user.email,
                loginTime: new Date().toISOString()
            };

            sessionStorage.setItem('currentUser', JSON.stringify(userData));

            if (role === 'admin') {
                window.location.href = 'index.html';
            } else {
                window.location.href = 'user-dashboard.html';
            }
        } else {
            showError('Invalid username, password, or role combination');
        }
    } else {
        showError('User not found');
    }
}

// Logout handler
function handleLogout() {
    if (confirm('Are you sure you want to logout?')) {
        sessionStorage.removeItem('currentUser');
        window.location.href = 'login.html';
    }
}

// Show error message
function showError(message) {
    alert(message);
}

// Initialize dashboard on page load
document.addEventListener('DOMContentLoaded', function() {
    const currentPage = window.location.pathname.split('/').pop() || 'login.html';

    if (!PUBLIC_PAGES.includes(currentPage)) {
        if (!checkAuthentication()) {
            return;
        }

        updateUserProfile();
        setActiveMenuItem();
    }
});

// Update user profile in sidebar
function updateUserProfile() {
    const currentUser = getCurrentUser();
    if (!currentUser) return;

    const userNameElements = document.querySelectorAll('#userName, #displayName');
    userNameElements.forEach(el => {
        el.textContent = currentUser.name;
    });

    const userRoleElement = document.getElementById('userRole');
    if (userRoleElement) {
        userRoleElement.textContent = currentUser.role.toUpperCase();
    }

    const userAvatarElement = document.getElementById('userInitial');
    if (userAvatarElement) {
        userAvatarElement.textContent = currentUser.name.charAt(0).toUpperCase();
    }
}

// Set active menu item based on current page
function setActiveMenuItem() {
    const currentPage = window.location.pathname.split('/').pop() || 'index.html';
    const menuItems = document.querySelectorAll('.menu-item');

    menuItems.forEach(item => {
        const href = item.getAttribute('href');
        if (href === currentPage || (currentPage === '' && href === 'index.html')) {
            item.classList.add('active');
        } else {
            item.classList.remove('active');
        }
    });
}

// Role-based access control
function hasRole(requiredRole) {
    const currentUser = getCurrentUser();
    if (!currentUser) return false;

    if (Array.isArray(requiredRole)) {
        return requiredRole.includes(currentUser.role);
    }

    return currentUser.role === requiredRole;
}

// Hide elements based on role
function hideForRole(element, requiredRole) {
    if (!hasRole(requiredRole)) {
        element.style.display = 'none';
    }
}

// Enable/disable elements based on role
function disableForRole(element, requiredRole) {
    if (!hasRole(requiredRole)) {
        element.disabled = true;
        element.style.opacity = '0.5';
        element.style.cursor = 'not-allowed';
    }
}