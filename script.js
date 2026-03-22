/**
 * FASHION HUB – main script
 * Uses the PHP/MySQL backend API.
 */

// ───────────────────────────────────────────────
// API helper
// ───────────────────────────────────────────────

const API_BASE = 'backend/api';

async function apiCall(endpoint, options = {}) {
    const merged = {
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        ...options,
    };
    const url = `${API_BASE}/${endpoint}`;
    let res;
    try {
        res = await fetch(url, merged);
    } catch (error) {
        const protocol = window.location.protocol;
        if (protocol === 'file:') {
            throw new Error('Network error: run the project through a local server (e.g. php -S 127.0.0.1:8000) and open http://127.0.0.1:8000/index.html.');
        }
        throw new Error(`Network error: could not reach ${url}. Ensure your PHP server is running and backend/api is accessible.`);
    }
    const raw = await res.text();

    let payload = null;
    if (raw) {
        try {
            payload = JSON.parse(raw);
        } catch {
            const phpLimitHint = /post_max_size|content-length|request entity too large|payload too large/i.test(raw);
            if (res.status === 413 || phpLimitHint) {
                throw new Error('Upload is too large for the server limit. Reduce file size or increase PHP post_max_size.' );
            }
                const preview = raw.slice(0, 180).replace(/\s+/g, ' ').trim();
                throw new Error(`Server returned ${res.status} with a non-JSON response from ${url}. ${preview ? `Response preview: ${preview}` : ''}`.trim());
        }
    }

    if (!res.ok) {
        throw new Error(payload?.message || `Request failed with status ${res.status}.`);
    }

    return payload || { status: 'ok', data: {} };
}

// ───────────────────────────────────────────────
// localStorage persistence (fallback / cache)
// ───────────────────────────────────────────────

function loadProducts() {
    try { return JSON.parse(localStorage.getItem('fashionProducts') || '[]'); }
    catch { return []; }
}

function saveProducts() {
    localStorage.setItem('fashionProducts', JSON.stringify(products));
}

function loadVerification() {
    try {
        return JSON.parse(localStorage.getItem('retailerVerification') || 'null')
            || { phoneVerified: false, emailVerified: false, isApproved: false, reviewStatus: 'unverified', reviewNotes: '' };
    } catch { return { phoneVerified: false, emailVerified: false, isApproved: false, reviewStatus: 'unverified', reviewNotes: '' }; }
}

function saveVerification() {
    localStorage.setItem('retailerVerification', JSON.stringify(retailerVerification));
}

function loadClientUsers() {
    try { return JSON.parse(localStorage.getItem('fashionClientUsers') || '[]'); }
    catch { return []; }
}

function saveClientUsers() {
    localStorage.setItem('fashionClientUsers', JSON.stringify(clientUsers));
}

function loadClientSession() {
    try { return JSON.parse(localStorage.getItem('fashionClientSession') || 'null'); }
    catch { return null; }
}

function saveClientSession() {
    localStorage.setItem('fashionClientSession', JSON.stringify(currentClient));
}

function clearClientSession() {
    localStorage.removeItem('fashionClientSession');
}

// ───────────────────────────────────────────────
// State
// ───────────────────────────────────────────────

let products              = [];
let cart                  = [];
let nextId                = 1;
let registrationStep      = 1;
let retailerVerification  = loadVerification();
let currentPage           = 1;
let totalPages            = 1;
const pageSize            = 12;
let activeFilters         = { search: '', category: 'all', subcategory: 'all', sort: 'newest' };
let clientUsers           = loadClientUsers();
let currentClient         = loadClientSession();
let chatRole              = null;
let activeChatRetailerId  = 0;
let activeChatRetailerName = '';
let activeChatClientEmail = '';
let activeChatClientName  = '';
let chatRefreshTimer      = null;
let clientRetailerDirectory = [];

const SUBCATEGORY_MAP = {
    men: ['shirts', 'trousers', 't-shirts', 'jackets', 'suits', 'hoodies', 'accessories'],
    women: ['dresses', 'tops', 'trousers', 'skirts', 'scarves', 'jackets', 'accessories'],
    kids: ['shirts', 'trousers', 'dresses', 'shorts', 'sweaters', 'school-wear', 'accessories'],
};

const defaultRetailerState = {
    phoneVerified: false,
    emailVerified: false,
    isApproved: false,
    reviewStatus: 'unverified',
    reviewNotes: '',
};

document.addEventListener('DOMContentLoaded', async function () {
    setupEventListeners();
    setupRegistrationListeners();
    setupClientAuthListeners();
    setupChatListeners();
    updateClientAccessUI();

    if (isClientLoggedIn()) {
        await checkRetailerStatusFromAPI();
        try {
            await fetchProductsFromAPI(1);
        } catch {
            const grid = document.getElementById('products-grid');
            grid.innerHTML = '<p style="grid-column:1/-1;text-align:center;color:#b91c1c;padding:2rem;">Unable to load products right now.</p>';
        }
    } else {
        renderLockedProductsPlaceholder();
        updateVerificationUI();
    }

    updateCartCount();
    refreshChatAccess();
    updateClientBottomNav();
});

function isClientLoggedIn() {
    return !!(currentClient && currentClient.email);
}

function renderLockedProductsPlaceholder() {
    const grid = document.getElementById('products-grid');
    if (!grid) return;
    grid.innerHTML = '<p style="grid-column:1/-1;text-align:center;color:#7a5000;padding:2rem;background:#fff7e6;border:1px solid #ffcf66;border-radius:10px;">Log in as a client to view retailer products.</p>';
    currentPage = 1;
    totalPages = 1;
    updatePaginationControls();
}

function updateClientAccessUI() {
    const authFormsPanel = document.getElementById('auth-forms-panel');
    const authSessionPanel = document.getElementById('auth-session-panel');
    const welcome = document.getElementById('client-welcome');
    const headerAuthAction = document.getElementById('header-auth-action');
    const lockMessage = document.getElementById('client-content-lock');
    const productsSection = document.querySelector('.products-section');
    const retailerSection = document.getElementById('retailer');
    const addPostTrigger = document.getElementById('add-post-trigger');

    if (isClientLoggedIn()) {
        if (welcome) welcome.textContent = `Welcome, ${currentClient.name || currentClient.email}!`;
        if (authFormsPanel) authFormsPanel.style.display = 'none';
        if (authSessionPanel) authSessionPanel.style.display = 'block';
        if (headerAuthAction) {
            headerAuthAction.textContent = 'Log Out';
            headerAuthAction.setAttribute('href', '#');
        }
        if (lockMessage) lockMessage.style.display = 'none';
        if (addPostTrigger) addPostTrigger.style.display = 'inline-block';
        productsSection?.classList.remove('section-locked');
        retailerSection?.classList.remove('section-locked');
        refreshChatAccess();
        updateClientBottomNav();
        return;
    }

    if (authFormsPanel) authFormsPanel.style.display = 'block';
    if (authSessionPanel) authSessionPanel.style.display = 'none';
    if (headerAuthAction) {
        headerAuthAction.textContent = 'Log In';
        headerAuthAction.setAttribute('href', '#client-access');
    }
    if (lockMessage) lockMessage.style.display = 'block';
    if (addPostTrigger) addPostTrigger.style.display = 'none';
    productsSection?.classList.add('section-locked');
    retailerSection?.classList.add('section-locked');
    setRetailerDashboardVisibility(false);
    refreshChatAccess();
    updateClientBottomNav();
}

function setRetailerDashboardVisibility(show) {
    const retailerSection = document.getElementById('retailer');
    if (!retailerSection) return;
    retailerSection.style.display = show ? 'block' : 'none';
}

function formatSubcategoryLabel(value) {
    return String(value || '')
        .split('-')
        .map(part => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function populateSubcategorySelect(target, category, options = {}) {
    const select = document.getElementById(target);
    if (!select) return;

    const includeAllOption = !!options.includeAllOption;
    const list = SUBCATEGORY_MAP[category] || [];
    const selected = String(options.selected || '').trim();

    if (includeAllOption) {
        select.innerHTML = '<option value="all">All Subcategories</option>';
    } else {
        select.innerHTML = '<option value="">Select Subcategory</option>';
    }

    if (list.length === 0) {
        if (includeAllOption) {
            select.disabled = true;
            select.value = 'all';
        } else {
            select.disabled = true;
            select.innerHTML = '<option value="">Select Category First</option>';
        }
        return;
    }

    for (const value of list) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = formatSubcategoryLabel(value);
        select.appendChild(option);
    }

    select.disabled = false;
    if (selected && list.includes(selected)) {
        select.value = selected;
    } else if (includeAllOption) {
        select.value = 'all';
    } else {
        select.value = '';
    }
}

function setupClientAuthListeners() {
    const showLoginBtn = document.getElementById('show-login-tab');
    const showSignupBtn = document.getElementById('show-signup-tab');
    const loginForm = document.getElementById('client-login-form');
    const signupForm = document.getElementById('client-signup-form');
    const headerAuthAction = document.getElementById('header-auth-action');
    const logoutBtn = document.getElementById('client-logout-btn');
    const goProductsBtn = document.getElementById('go-products-btn');
    const becomeRetailerBtn = document.getElementById('become-retailer-btn');

    showLoginBtn?.addEventListener('click', function () {
        showLoginBtn.classList.add('active');
        showSignupBtn.classList.remove('active');
        loginForm.style.display = 'block';
        signupForm.style.display = 'none';
    });

    showSignupBtn?.addEventListener('click', function () {
        showSignupBtn.classList.add('active');
        showLoginBtn.classList.remove('active');
        loginForm.style.display = 'none';
        signupForm.style.display = 'block';
    });

    signupForm?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const name = document.getElementById('client-signup-name').value.trim();
        const email = document.getElementById('client-signup-email').value.trim().toLowerCase();
        const password = document.getElementById('client-signup-password').value;
        const confirm = document.getElementById('client-signup-confirm').value;

        if (!name || !email || !password) {
            showSuccessMessage('Complete all signup fields.');
            return;
        }
        if (password.length < 6) {
            showSuccessMessage('Password must be at least 6 characters.');
            return;
        }
        if (password !== confirm) {
            showSuccessMessage('Passwords do not match.');
            return;
        }
        if (clientUsers.some(user => String(user.email).toLowerCase() === email)) {
            showSuccessMessage('An account with this email already exists.');
            return;
        }

        const user = { name, email, password, createdAt: new Date().toISOString() };
        clientUsers.push(user);
        saveClientUsers();
        currentClient = { name: user.name, email: user.email, createdAt: user.createdAt };
        saveClientSession();

        signupForm.reset();
        updateClientAccessUI();
        showSuccessMessage('Account created. You are now logged in.');
        await onClientLogin();
    });

    loginForm?.addEventListener('submit', async function (e) {
        e.preventDefault();
        const email = document.getElementById('client-login-email').value.trim().toLowerCase();
        const password = document.getElementById('client-login-password').value;
        const user = clientUsers.find(u => String(u.email).toLowerCase() === email && u.password === password);

        if (!user) {
            showSuccessMessage('Invalid email or password.');
            return;
        }

        currentClient = { name: user.name, email: user.email, createdAt: user.createdAt };
        saveClientSession();
        loginForm.reset();
        updateClientAccessUI();
        showSuccessMessage('Logged in successfully.');
        await onClientLogin();
    });

    const logoutHandler = function (e) {
        e.preventDefault();
        if (!isClientLoggedIn()) {
            document.getElementById('client-access')?.scrollIntoView({ behavior: 'smooth' });
            return;
        }
        currentClient = null;
        clearClientSession();
        retailerVerification = { ...defaultRetailerState };
        saveVerification();
        products = [];
        cart = [];
        updateClientAccessUI();
        updateVerificationUI();
        updateCartCount();
        renderLockedProductsPlaceholder();
        showSuccessMessage('You have been logged out.');
    };

    headerAuthAction?.addEventListener('click', logoutHandler);
    logoutBtn?.addEventListener('click', logoutHandler);

    goProductsBtn?.addEventListener('click', function () {
        document.getElementById('products')?.scrollIntoView({ behavior: 'smooth' });
    });

    becomeRetailerBtn?.addEventListener('click', function () {
        openRegistrationModal();
    });
}

async function onClientLogin() {
    await checkRetailerStatusFromAPI();
    updateVerificationUI();
    try {
        await fetchProductsFromAPI(1);
    } catch {
        const grid = document.getElementById('products-grid');
        grid.innerHTML = '<p style="grid-column:1/-1;text-align:center;color:#b91c1c;padding:2rem;">Unable to load products right now.</p>';
    }
    refreshChatAccess();
}

// ───────────────────────────────────────────────
// Products: fetch from API
// ───────────────────────────────────────────────

async function fetchProductsFromAPI(page = 1) {
    if (!isClientLoggedIn()) {
        renderLockedProductsPlaceholder();
        return;
    }

    const grid = document.getElementById('products-grid');
    grid.innerHTML = '<p style="grid-column:1/-1;text-align:center;color:#999;padding:2rem;">Loading products…</p>';
    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('limit', String(pageSize));
    if (activeFilters.category !== 'all') params.set('category', activeFilters.category);
    if (activeFilters.subcategory !== 'all') params.set('subcategory', activeFilters.subcategory);
    if (activeFilters.search) params.set('search', activeFilters.search);
    if (activeFilters.sort) params.set('sort', activeFilters.sort);

    const res = await apiCall(`products/list.php?${params.toString()}`);
    if (res.status !== 'ok') {
        throw new Error(res.message || 'Failed to fetch products.');
    }

    products = res.data.products || [];
    const pagination = res.data.pagination || {};
    currentPage = Number(pagination.page || page);
    totalPages = Number(pagination.total_pages || 1);

    nextId = products.reduce((max, p) => Math.max(max, p.id), 0) + 1;
    displayProducts(products);
    updatePaginationControls();
    populateClientRetailerOptions();
}

async function checkRetailerStatusFromAPI() {
    if (!isClientLoggedIn()) {
        retailerVerification = { ...defaultRetailerState };
        saveVerification();
        return;
    }

    try {
        const res = await apiCall('retailers/status.php');
        if (res.status === 'ok') {
            const d = res.data;
            retailerVerification.isApproved    = !!d.is_approved;
            retailerVerification.phoneVerified = !!d.phone_verified;
            retailerVerification.emailVerified = !!d.email_verified;
            retailerVerification.reviewStatus  = d.review_status || 'unverified';
            retailerVerification.reviewNotes   = d.review_notes || '';
            if (d.shop_name)   retailerVerification.shopName   = d.shop_name;
            if (d.retailer_id) retailerVerification.retailerId = d.retailer_id;
            saveVerification();
        }
    } catch {
        showSuccessMessage('Unable to verify retailer status. Please refresh and try again.');
    }
    updateVerificationUI();
}

function updatePaginationControls() {
    const prev = document.getElementById('products-prev');
    const next = document.getElementById('products-next');
    const label = document.getElementById('products-page-label');
    if (!prev || !next || !label) return;

    prev.disabled = currentPage <= 1;
    next.disabled = currentPage >= totalPages;
    label.textContent = `Page ${currentPage} of ${totalPages}`;
}

// Setup event listeners
function setupEventListeners() {
    setupBottomNavListeners();

    // Product form submission
    document.getElementById('add-product-form').addEventListener('submit', function(e) {
        e.preventDefault();
        addNewProduct();
    });

    // Image file picker → live preview
    document.getElementById('product-image').addEventListener('change', function () {
        const preview = document.getElementById('product-image-preview');
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = e => {
                preview.src = e.target.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(this.files[0]);
        } else {
            preview.src = '';
            preview.style.display = 'none';
        }
    });

    // Search functionality
    document.getElementById('search-input').addEventListener('input', filterProducts);
    
    // Category filter
    document.getElementById('category-filter').addEventListener('change', function () {
        const category = this.value;
        populateSubcategorySelect('subcategory-filter', category, { includeAllOption: true });
        filterProducts();
    });

    // Subcategory filter
    document.getElementById('subcategory-filter').addEventListener('change', filterProducts);
    
    // Sort filter
    document.getElementById('sort-filter').addEventListener('change', filterProducts);

    // Product form category/subcategory dependency
    document.getElementById('product-category').addEventListener('change', function () {
        populateSubcategorySelect('product-subcategory', this.value);
    });

    // Initial state for subcategory selectors
    populateSubcategorySelect('subcategory-filter', 'all', { includeAllOption: true });
    populateSubcategorySelect('product-subcategory', '');

    // Products pagination
    document.getElementById('products-prev').addEventListener('click', async function () {
        if (currentPage > 1) {
            try {
                await fetchProductsFromAPI(currentPage - 1);
            } catch {
                showSuccessMessage('Unable to load previous page.');
            }
        }
    });
    document.getElementById('products-next').addEventListener('click', async function () {
        if (currentPage < totalPages) {
            try {
                await fetchProductsFromAPI(currentPage + 1);
            } catch {
                showSuccessMessage('Unable to load next page.');
            }
        }
    });

    // Cart modal
    document.querySelector('.cart-btn').addEventListener('click', function (e) {
        e.preventDefault();
        if (!isClientLoggedIn()) {
            showSuccessMessage('Log in as a client to use the cart.');
            document.getElementById('client-access')?.scrollIntoView({ behavior: 'smooth' });
            return;
        }
        openCart();
    });
    document.querySelector('#cart-modal .close').addEventListener('click', closeCart);

    // Retailer registration access points
    document.getElementById('open-registration-btn').addEventListener('click', openRegistrationModal);
    document.getElementById('add-post-trigger').addEventListener('click', function () {
        if (!isClientLoggedIn()) {
            showSuccessMessage('Please log in as a client first.');
            document.getElementById('client-access')?.scrollIntoView({ behavior: 'smooth' });
            return;
        }

        if (!retailerVerification.isApproved) {
            openRegistrationModal();
            return;
        }

        setRetailerDashboardVisibility(true);
        document.getElementById('retailer').scrollIntoView({ behavior: 'smooth' });
    });
    document.getElementById('close-retailer-dashboard').addEventListener('click', function () {
        setRetailerDashboardVisibility(false);
        document.getElementById('products')?.scrollIntoView({ behavior: 'smooth' });
    });
    document.getElementById('sell-link').addEventListener('click', function(e) {
        e.preventDefault();
        if (!isClientLoggedIn()) {
            showSuccessMessage('Please log in as a client before becoming a retailer.');
            document.getElementById('client-access')?.scrollIntoView({ behavior: 'smooth' });
            return;
        }
        if (!retailerVerification.isApproved) {
            openRegistrationModal();
            return;
        }
        setRetailerDashboardVisibility(true);
        document.getElementById('retailer').scrollIntoView({ behavior: 'smooth' });
    });
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        const cartModal = document.getElementById('cart-modal');
        const registrationModal = document.getElementById('registration-modal');

        if (event.target === cartModal) {
            closeCart();
        }

        if (event.target === registrationModal) {
            closeRegistrationModal();
        }
    });
}

function setupBottomNavListeners() {
    const homeBtn = document.getElementById('bottom-nav-home');
    const chatBtn = document.getElementById('bottom-nav-chat');
    const profileBtn = document.getElementById('bottom-nav-profile');

    homeBtn?.addEventListener('click', function () {
        setBottomNavActive('home');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    chatBtn?.addEventListener('click', function () {
        setBottomNavActive('chat');
        document.getElementById('chat-hub')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    profileBtn?.addEventListener('click', function () {
        setBottomNavActive('profile');
        document.getElementById('client-access')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
}

function setBottomNavActive(tab) {
    const homeBtn = document.getElementById('bottom-nav-home');
    const chatBtn = document.getElementById('bottom-nav-chat');
    const profileBtn = document.getElementById('bottom-nav-profile');

    homeBtn?.classList.toggle('active', tab === 'home');
    chatBtn?.classList.toggle('active', tab === 'chat');
    profileBtn?.classList.toggle('active', tab === 'profile');
}

function updateClientBottomNav() {
    const nav = document.getElementById('client-bottom-nav');
    if (!nav) return;

    if (isClientLoggedIn()) {
        nav.style.display = '';
        document.body.classList.add('has-client-bottom-nav');
    } else {
        nav.style.display = 'none';
        document.body.classList.remove('has-client-bottom-nav');
    }
}

function setupChatListeners() {
    const retailerSelect = document.getElementById('chat-retailer-select');
    const retailerSearch = document.getElementById('chat-retailer-search');
    const clientSelect = document.getElementById('chat-client-select');
    const chatForm = document.getElementById('chat-form');
    const reportChatBtn = document.getElementById('report-chat-btn');

    reportChatBtn?.addEventListener('click', async function () {
        await reportCurrentChat();
    });

    retailerSearch?.addEventListener('input', function () {
        renderClientRetailerSelect(this.value || '');
    });

    retailerSelect?.addEventListener('change', async function () {
        const selected = this.value.trim();
        if (!selected) {
            activeChatRetailerId = 0;
            activeChatRetailerName = '';
            activeChatClientEmail = '';
            activeChatClientName = '';
            renderChatThread([]);
            toggleChatThread(false);
            return;
        }

        const [idPart, namePart] = selected.split('|');
        activeChatRetailerId = Number(idPart || 0);
        activeChatRetailerName = String(namePart || 'Retailer');
        activeChatClientEmail = String(currentClient?.email || '').toLowerCase();
        activeChatClientName = String(currentClient?.name || 'Client');
        await loadChatMessages();
    });

    clientSelect?.addEventListener('change', async function () {
        const selected = this.value.trim();
        if (!selected) {
            activeChatClientEmail = '';
            activeChatClientName = '';
            renderChatThread([]);
            toggleChatThread(false);
            return;
        }

        const [emailPart, namePart] = selected.split('|');
        activeChatRetailerId = Number(retailerVerification.retailerId || 0);
        activeChatRetailerName = String(retailerVerification.shopName || 'Retailer');
        activeChatClientEmail = String(emailPart || '').toLowerCase();
        activeChatClientName = String(namePart || 'Client');
        await loadChatMessages();
    });

    chatForm?.addEventListener('submit', async function (e) {
        e.preventDefault();
        await sendChatMessage();
    });
}

function setupRegistrationListeners() {
    document.querySelector('.registration-close').addEventListener('click', closeRegistrationModal);
    document.getElementById('registration-next').addEventListener('click', nextRegistrationStep);
    document.getElementById('registration-back').addEventListener('click', previousRegistrationStep);
    document.getElementById('retailer-registration-form').addEventListener('submit', completeRetailerVerification);
}

// Display products in grid
function displayProducts(productsToDisplay) {
    const grid = document.getElementById('products-grid');

    if (productsToDisplay.length === 0) {
        grid.innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: #666; padding: 2rem;">No products found.</p>';
        return;
    }

    grid.innerHTML = productsToDisplay.map(product => `
        <div class="product-card">
            ${product.image ? `<img src="${escHtml(product.image)}" alt="${escHtml(product.name)}"
                style="width:100%;height:220px;object-fit:cover;border-radius:12px;"` +
                ` onerror="this.style.display='none';">` : ''}
            <div class="product-info">
                <div class="product-header">
                    <h3 class="product-name">${escHtml(product.name)}</h3>
                    <span class="product-price">$${product.price.toFixed(2)}</span>
                </div>
                <span class="retailer-name">by ${escHtml(product.retailer)}</span>
                <span class="product-category">${escHtml(product.category)}</span>
                ${product.subcategory ? `<span class="product-category" style="margin-left:0.35rem; background:#fdebdc; color:#8f461f;">${escHtml(formatSubcategoryLabel(product.subcategory))}</span>` : ''}
                <p class="product-sizes" style="margin-top:0.45rem; margin-bottom:0.35rem;">
                    ${product.retailer_verified ? '✔️ Verified seller' : '⚠️ Seller pending verification'}
                    ${product.retailer_town || product.retailer_county ? ` • 📍 ${escHtml([product.retailer_town, product.retailer_county].filter(Boolean).join(', '))}` : ''}
                    ${product.retailer_id ? ` • ⭐ ${retailerRating(product.retailer_id)}` : ''}
                </p>
                <p class="product-description">${escHtml(product.description)}</p>
                <p class="product-sizes"><strong>Sizes:</strong> ${escHtml(product.sizes)}</p>
                <div style="display:flex; gap:0.5rem; margin-top:0.5rem;">
                    <button class="btn-add-cart" style="flex:1;" onclick="addToCart(${product.id})">Add to Cart</button>
                    <a href="product_page.html?id=${product.id}" class="btn-add-cart"
                       style="flex:1; text-align:center; text-decoration:none; display:flex; align-items:center;
                              justify-content:center; background:white; color:#10b981; border:2px solid #10b981;">
                        View Details
                    </a>
                </div>
                <div style="margin-top:0.55rem;">
                    <button type="button" class="report-btn" onclick="reportProduct(${product.id})">Report Seller</button>
                </div>
                ${isProductOwner(product) ? `
                <div style="margin-top:0.5rem;">
                    <button type="button" onclick="deleteProduct(${product.id})"
                        style="width:100%; padding:0.6rem; border:none; border-radius:8px; cursor:pointer;
                               background:#dc2626; color:#fff; font-weight:600;">
                        Delete Post
                    </button>
                </div>` : ''}
            </div>
        </div>
    `).join('');
}

function isProductOwner(product) {
    const myId = Number(retailerVerification.retailerId || 0);
    const productRetailerId = Number(product.retailer_id || 0);
    if (myId > 0 && productRetailerId > 0) {
        return myId === productRetailerId;
    }
    // Fallback for older records where retailer_id may not exist.
    return !!retailerVerification.shopName &&
        String(product.retailer || '').trim().toLowerCase() === String(retailerVerification.shopName).trim().toLowerCase();
}

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function retailerRating(retailerId) {
    const id = Number(retailerId || 0);
    const score = 4.1 + ((id % 10) * 0.08);
    return Math.min(4.9, score).toFixed(1);
}

async function submitReport(payload) {
    const res = await apiCall('reports/submit.php', {
        method: 'POST',
        body: JSON.stringify(payload),
    });
    if (res.status !== 'ok') {
        throw new Error(res.message || 'Failed to submit report.');
    }
}

async function reportProduct(productId) {
    if (!isClientLoggedIn()) {
        showSuccessMessage('Log in as a client to report a seller.');
        return;
    }

    const reason = prompt('Reason for report (e.g. scam, fake product, abusive behavior):');
    if (!reason || !reason.trim()) {
        return;
    }

    try {
        await submitReport({
            report_type: 'product',
            reporter_name: String(currentClient?.name || 'Client'),
            reporter_email: String(currentClient?.email || '').toLowerCase(),
            reason: reason.trim().slice(0, 120),
            notes: '',
            product_id: Number(productId),
        });
        showSuccessMessage('Report submitted. Our team will review it.');
    } catch (error) {
        showSuccessMessage(error?.message || 'Failed to submit report.');
    }
}

async function reportCurrentChat() {
    if (!isClientLoggedIn()) {
        showSuccessMessage('Log in as a client to report chat issues.');
        return;
    }

    if (!activeChatRetailerId || !activeChatClientEmail) {
        showSuccessMessage('Open a chat thread first before reporting.');
        return;
    }

    const reason = prompt('Reason for chat report (e.g. scam attempt, harassment):');
    if (!reason || !reason.trim()) {
        return;
    }

    try {
        await submitReport({
            report_type: 'chat',
            reporter_name: String(currentClient?.name || 'Client'),
            reporter_email: String(currentClient?.email || '').toLowerCase(),
            reason: reason.trim().slice(0, 120),
            notes: '',
            retailer_id: Number(activeChatRetailerId),
            chat_client_email: String(activeChatClientEmail || '').toLowerCase(),
        });
        showSuccessMessage('Chat report submitted. Thank you.');
    } catch (error) {
        showSuccessMessage(error?.message || 'Failed to submit chat report.');
    }
}

async function readFileInputAsDataUrl(inputId, required = false) {
    const input = document.getElementById(inputId);
    const file = input?.files?.[0] || null;
    const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

    if (!file) {
        if (required) {
            throw new Error(`${inputId} is required.`);
        }
        return '';
    }

    if (file.size > MAX_UPLOAD_BYTES) {
        throw new Error(`${inputId} is too large. Max size is 2MB.`);
    }

    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = e => resolve(String(e.target?.result || ''));
        reader.onerror = () => reject(new Error(`Failed to read ${inputId}.`));
        reader.readAsDataURL(file);
    });
}

// Add new product (retailer functionality)
async function addNewProduct() {
    if (!retailerVerification.isApproved) {
        showSuccessMessage('Complete retailer verification before posting products.');
        openRegistrationModal();
        return;
    }

    const productData = {
        name:        document.getElementById('product-name').value.trim(),
        price:       parseFloat(document.getElementById('product-price').value),
        category:    document.getElementById('product-category').value,
        subcategory: document.getElementById('product-subcategory').value,
        image:       '',   // filled after reading file below
        sizes:       document.getElementById('product-sizes').value.trim(),
        description: document.getElementById('product-description').value.trim(),
    };

    if (!productData.subcategory) {
        showSuccessMessage('Please choose a subcategory.');
        return;
    }

    // Read the selected image file as a data URL
    const fileInput = document.getElementById('product-image');
    if (fileInput.files && fileInput.files[0]) {
        try {
            productData.image = await new Promise((resolve, reject) => {
                const reader = new FileReader();
                reader.onload  = e => resolve(e.target.result);
                reader.onerror = () => reject(new Error('Failed to read image file.'));
                reader.readAsDataURL(fileInput.files[0]);
            });
        } catch {
            showSuccessMessage('Failed to read image file. Please choose another image.');
            return;
        }
    } else {
        showSuccessMessage('Please select an image file.');
        return;
    }

    try {
        const res = await apiCall('products/add.php', {
            method: 'POST',
            body: JSON.stringify(productData),
        });
        if (res.status === 'ok') {
            products.unshift(res.data.product);
            saveProducts();
            displayProducts(products);
            document.getElementById('add-product-form').reset();
            populateSubcategorySelect('product-subcategory', '');
            document.getElementById('retailer-name').value = retailerVerification.shopName || '';
            const preview = document.getElementById('product-image-preview');
            if (preview) {
                preview.src = '';
                preview.style.display = 'none';
            }
            showSuccessMessage('Product added successfully!');
            document.getElementById('products').scrollIntoView({ behavior: 'smooth' });
        } else {
            showSuccessMessage(res.message || 'Failed to add product.');
        }
    } catch {
        showSuccessMessage('Failed to add product. Please try again.');
    }
}

function openRegistrationModal() {
    if (!isClientLoggedIn()) {
        showSuccessMessage('Log in as a client first, then continue with retailer verification.');
        document.getElementById('client-access')?.scrollIntoView({ behavior: 'smooth' });
        return;
    }

    if (retailerVerification.reviewStatus === 'pending' || retailerVerification.reviewStatus === 'rejected' || retailerVerification.isApproved) {
        window.location.href = 'retailer_registration.html';
        return;
    }
    document.getElementById('registration-modal').style.display = 'block';
    registrationStep = 1;
    renderRegistrationStep();
}

function closeRegistrationModal() {
    document.getElementById('registration-modal').style.display = 'none';
}

function renderRegistrationStep() {
    const steps = document.querySelectorAll('.registration-step');
    const indicators = document.querySelectorAll('.step-indicator');

    steps.forEach(step => {
        const stepNumber = parseInt(step.dataset.step, 10);
        step.classList.toggle('active', stepNumber === registrationStep);
    });

    indicators.forEach(indicator => {
        const indicatorNumber = parseInt(indicator.dataset.indicator, 10);
        indicator.classList.toggle('active', indicatorNumber === registrationStep);
    });

    document.getElementById('registration-back').style.visibility = registrationStep === 1 ? 'hidden' : 'visible';
    document.getElementById('registration-next').style.display = registrationStep === 3 ? 'none' : 'inline-block';
    document.getElementById('registration-submit').style.display = registrationStep === 3 ? 'inline-block' : 'none';
}

function nextRegistrationStep() {
    if (!validateRegistrationStep(registrationStep)) {
        return;
    }

    if (registrationStep < 3) {
        registrationStep += 1;
        renderRegistrationStep();
    }
}

function previousRegistrationStep() {
    if (registrationStep > 1) {
        registrationStep -= 1;
        renderRegistrationStep();
    }
}

function validateRegistrationStep(stepNumber) {
    const step = document.querySelector(`.registration-step[data-step="${stepNumber}"]`);
    const requiredFields = step.querySelectorAll('[required]');

    for (const field of requiredFields) {
        if (field.type === 'checkbox' && !field.checked) {
            alert('Please accept the declaration to continue.');
            field.focus();
            return false;
        }

        if (field.type === 'file' && field.files.length === 0) {
            alert('Please upload all required files before continuing.');
            field.focus();
            return false;
        }

        if (field.type !== 'checkbox' && field.type !== 'file' && !field.value.trim()) {
            alert('Please complete all required fields before continuing.');
            field.focus();
            return false;
        }
    }

    return true;
}

async function completeRetailerVerification(e) {
    e.preventDefault();
    if (!validateRegistrationStep(3)) return;
    let documents;
    try {
        documents = {
            id_photo: await readFileInputAsDataUrl('id-photo', true),
            selfie_photo: await readFileInputAsDataUrl('selfie-photo', true),
            shop_photo: await readFileInputAsDataUrl('shop-photo', true),
            signboard_photo: await readFileInputAsDataUrl('signboard-photo', true),
            business_permit: await readFileInputAsDataUrl('business-permit'),
            kra_pin: await readFileInputAsDataUrl('kra-pin'),
            business_certificate: await readFileInputAsDataUrl('business-certificate'),
        };
    } catch (error) {
        alert(error.message || 'Please upload all required verification documents.');
        return;
    }

    const formData = {
        full_name:     document.getElementById('full-name').value.trim(),
        national_id:   document.getElementById('national-id').value.trim(),
        phone:         document.getElementById('phone-number').value.trim(),
        email:         document.getElementById('email-address').value.trim(),
        date_of_birth: document.getElementById('date-of-birth').value,
        shop_name:     document.getElementById('shop-name').value.trim(),
        business_type: document.getElementById('business-type').value,
        county:        document.getElementById('shop-county').value.trim(),
        town:          document.getElementById('shop-town').value.trim(),
        shop_address:  document.getElementById('shop-address').value.trim(),
        shop_map_url:  document.getElementById('shop-map').value.trim(),
        documents,
    };
    try {
        const res = await apiCall('retailers/register.php', {
            method: 'POST',
            body: JSON.stringify(formData),
        });
        if (res.status === 'ok') {
            retailerVerification.isApproved = false;
            retailerVerification.shopName = formData.shop_name;
            retailerVerification.retailerId = res.data.retailer_id;
            retailerVerification.reviewStatus = res.data.review_status || 'pending';
            saveVerification();
            updateVerificationUI();
            document.getElementById('retailer-name').value = formData.shop_name;
            closeRegistrationModal();
            showSuccessMessage('Verification documents submitted. Your account is now pending admin review.');
            document.getElementById('retailer').scrollIntoView({ behavior: 'smooth' });
        } else {
            alert(res.message || 'Registration failed.');
        }
    } catch (error) {
        alert(error?.message || 'Registration failed. Please try again.');
    }
}

function updateVerificationUI() {
    const verificationBanner = document.getElementById('verification-banner');
    const status = document.getElementById('verification-status');
    const openBtn = document.getElementById('open-registration-btn');
    const fieldset = document.getElementById('product-form-fieldset');

    if (!isClientLoggedIn()) {
        verificationBanner.classList.remove('verified');
        verificationBanner.classList.add('not-verified');
        status.textContent = 'Log in as a client to start retailer verification and product posting.';
        openBtn.textContent = 'Log In to Continue';
        fieldset.disabled = true;
        document.getElementById('retailer-name').value = '';
        setRetailerDashboardVisibility(false);
        return;
    }

    if (retailerVerification.isApproved) {
        verificationBanner.classList.remove('not-verified');
        verificationBanner.classList.add('verified');
        status.textContent = 'Approved seller account: you can now add products to FASHION HUB.';
        openBtn.textContent = 'View Status';
        fieldset.disabled = false;
        // Restore shop name into retailer field
        if (retailerVerification.shopName) {
            document.getElementById('retailer-name').value = retailerVerification.shopName;
        }
        return;
    }

    if (retailerVerification.reviewStatus === 'pending') {
        verificationBanner.classList.remove('verified');
        verificationBanner.classList.add('not-verified');
        status.textContent = 'Legitimacy documents submitted. Your seller account is pending admin review.';
        openBtn.textContent = 'View Status';
        fieldset.disabled = true;
        return;
    }

    if (retailerVerification.reviewStatus === 'rejected') {
        verificationBanner.classList.remove('verified');
        verificationBanner.classList.add('not-verified');
        status.textContent = retailerVerification.reviewNotes
            ? `Verification rejected: ${retailerVerification.reviewNotes}`
            : 'Verification rejected. Please contact support or re-submit your legitimacy documents.';
        openBtn.textContent = 'View Status';
        fieldset.disabled = true;
        return;
    }

    verificationBanner.classList.remove('verified');
    verificationBanner.classList.add('not-verified');
    status.textContent = 'Complete identity, contact, and shop verification before posting clothes.';
    openBtn.textContent = 'Start Verification';
    fieldset.disabled = true;
}

// Filter products
async function filterProducts() {
    activeFilters.search = document.getElementById('search-input').value.trim();
    activeFilters.category = document.getElementById('category-filter').value;
    activeFilters.subcategory = document.getElementById('subcategory-filter').value;
    activeFilters.sort = document.getElementById('sort-filter').value;
    try {
        await fetchProductsFromAPI(1);
    } catch {
        showSuccessMessage('Unable to apply filters right now.');
    }
}

async function deleteProduct(productId) {
    const product = products.find(p => p.id === productId);
    if (!product) {
        showSuccessMessage('Product not found.');
        return;
    }

    if (!isProductOwner(product)) {
        showSuccessMessage('You can only delete your own posts.');
        return;
    }

    if (!confirm('Delete this post permanently?')) {
        return;
    }

    try {
        const res = await apiCall('products/delete.php', {
            method: 'POST',
            body: JSON.stringify({ id: productId }),
        });

        if (res.status === 'ok') {
            products = products.filter(p => p.id !== productId);
            saveProducts();
            displayProducts(products);
            showSuccessMessage('Post deleted successfully.');
        } else {
            showSuccessMessage(res.message || 'Failed to delete post.');
        }
    } catch {
        showSuccessMessage('Failed to delete post. Please try again.');
    }
}

// Add to cart
function addToCart(productId) {
    if (!isClientLoggedIn()) {
        showSuccessMessage('Log in as a client to add items to cart.');
        return;
    }

    const product = products.find(p => p.id === productId);
    if (product) {
        cart.push({...product});
        updateCartCount();
        showSuccessMessage(`${product.name} added to cart!`);
    }
}

// Update cart count
function updateCartCount() {
    document.getElementById('cart-count').textContent = cart.length;
}

// Open cart modal
function openCart() {
    const modal = document.getElementById('cart-modal');
    const cartItemsContainer = document.getElementById('cart-items');

    if (cart.length === 0) {
        cartItemsContainer.innerHTML = '<div class="empty-cart"><p>Your cart is empty</p></div>';
        document.getElementById('cart-total').textContent = '0.00';
    } else {
        cartItemsContainer.innerHTML = cart.map((item, index) => `
            <div class="cart-item">
                <img src="${escHtml(item.image)}" alt="${escHtml(item.name)}" class="cart-item-image"
                     onerror="this.src='https://via.placeholder.com/80?text=No+Image'">
                <div class="cart-item-info">
                    <div class="cart-item-name">${escHtml(item.name)}</div>
                    <div class="cart-item-retailer">by ${escHtml(item.retailer)}</div>
                    <div class="cart-item-price">$${item.price.toFixed(2)}</div>
                </div>
                <button class="cart-item-remove" onclick="removeFromCart(${index})">Remove</button>
            </div>
        `).join('');

        const total = cart.reduce((sum, item) => sum + item.price, 0);
        document.getElementById('cart-total').textContent = total.toFixed(2);
    }

    modal.style.display = 'block';
}

// Close cart modal
function closeCart() {
    document.getElementById('cart-modal').style.display = 'none';
}

// Remove from cart
function removeFromCart(index) {
    cart.splice(index, 1);
    updateCartCount();
    openCart(); // Refresh cart display
}

// Clear cart
function clearCart() {
    if (confirm('Are you sure you want to clear your cart?')) {
        cart = [];
        updateCartCount();
        openCart(); // Refresh cart display
    }
}

// Checkout
function checkout() {
    if (cart.length === 0) {
        alert('Your cart is empty!');
        return;
    }
    
    const total = cart.reduce((sum, item) => sum + item.price, 0);
    alert(`Thank you for your purchase!\n\nTotal: $${total.toFixed(2)}\n\nThis is a demo - no actual payment was processed.`);
    
    cart = [];
    updateCartCount();
    closeCart();
}

// Show success message
function showSuccessMessage(message) {
    const msgDiv = document.createElement('div');
    msgDiv.className = 'success-message';
    msgDiv.textContent = message;
    document.body.appendChild(msgDiv);
    
    setTimeout(() => {
        msgDiv.remove();
    }, 3000);
}

function clearChatTimer() {
    if (chatRefreshTimer) {
        clearInterval(chatRefreshTimer);
        chatRefreshTimer = null;
    }
}

function refreshChatAccess() {
    const chatControls = document.getElementById('chat-controls');
    const clientControls = document.getElementById('chat-client-controls');
    const retailerControls = document.getElementById('chat-retailer-controls');
    const modeLabel = document.getElementById('chat-mode-label');

    clearChatTimer();
    resetChatSelection();

    if (!chatControls || !clientControls || !retailerControls || !modeLabel) {
        return;
    }

    if (!isClientLoggedIn()) {
        chatRole = null;
        chatControls.style.display = 'none';
        clientControls.style.display = 'none';
        retailerControls.style.display = 'none';
        modeLabel.textContent = 'Log in as a client or approved retailer to chat.';
        toggleChatThread(false);
        return;
    }

    chatControls.style.display = 'grid';

    if (retailerVerification.isApproved && Number(retailerVerification.retailerId || 0) > 0) {
        chatRole = 'retailer';
        clientControls.style.display = 'none';
        retailerControls.style.display = 'flex';
        modeLabel.textContent = `Retailer mode: ${retailerVerification.shopName || 'Verified retailer'}`;
        loadRetailerThreads();
    } else {
        chatRole = 'client';
        clientControls.style.display = 'flex';
        retailerControls.style.display = 'none';
        modeLabel.textContent = `Client mode: ${currentClient?.name || currentClient?.email || 'Client'}`;
        populateClientRetailerOptions();
    }
}

function resetChatSelection() {
    activeChatRetailerId = 0;
    activeChatRetailerName = '';
    activeChatClientEmail = '';
    activeChatClientName = '';

    const retailerSelect = document.getElementById('chat-retailer-select');
    const retailerSearch = document.getElementById('chat-retailer-search');
    const clientSelect = document.getElementById('chat-client-select');
    if (retailerSelect) retailerSelect.value = '';
    if (retailerSearch) retailerSearch.value = '';
    if (clientSelect) clientSelect.value = '';
}

function toggleChatThread(show) {
    const thread = document.getElementById('chat-thread');
    const emptyState = document.getElementById('chat-empty-state');
    const reportBtn = document.getElementById('report-chat-btn');
    if (!thread || !emptyState) return;

    thread.style.display = show ? 'block' : 'none';
    emptyState.style.display = show ? 'none' : 'block';
    if (reportBtn) {
        reportBtn.style.display = show ? 'inline-flex' : 'none';
    }
}

function populateClientRetailerOptions() {
    if (chatRole !== 'client') return;

    const map = new Map();

    for (const product of products) {
        const rid = Number(product.retailer_id || 0);
        const rname = String(product.retailer || '').trim();
        if (rid > 0 && rname) {
            map.set(rid, rname);
        }
    }

    clientRetailerDirectory = [...map.entries()]
        .map(([rid, name]) => ({ retailer_id: Number(rid), retailer_name: String(name) }))
        .sort((a, b) => a.retailer_name.localeCompare(b.retailer_name));

    renderClientRetailerSelect('');

    loadClientThreads();
}

function renderClientRetailerSelect(searchTerm = '') {
    const select = document.getElementById('chat-retailer-select');
    if (!select) return;

    const query = String(searchTerm || '').trim().toLowerCase();
    const existing = activeChatRetailerId;
    const filtered = query
        ? clientRetailerDirectory.filter(item => item.retailer_name.toLowerCase().includes(query))
        : clientRetailerDirectory;

    select.innerHTML = '<option value="">Select a retailer</option>';
    for (const item of filtered) {
        const option = document.createElement('option');
        option.value = `${item.retailer_id}|${item.retailer_name}`;
        option.textContent = item.retailer_name;
        if (existing === item.retailer_id) {
            option.selected = true;
        }
        select.appendChild(option);
    }
}

async function loadClientThreads() {
    if (chatRole !== 'client' || !isClientLoggedIn()) return;

    try {
        const email = String(currentClient?.email || '').toLowerCase();
        if (!email) return;
        const res = await apiCall(`chat/list_threads.php?role=client&client_email=${encodeURIComponent(email)}`);
        if (res.status !== 'ok') return;

        const select = document.getElementById('chat-retailer-select');
        for (const thread of (res.data.threads || [])) {
            const rid = Number(thread.retailer_id || 0);
            const name = String(thread.retailer_name || '').trim();
            if (rid <= 0 || !name) continue;
            const exists = clientRetailerDirectory.some(item => item.retailer_id === rid);
            if (!exists) {
                clientRetailerDirectory.push({ retailer_id: rid, retailer_name: name });
            }
        }
        clientRetailerDirectory.sort((a, b) => a.retailer_name.localeCompare(b.retailer_name));
        const searchInput = document.getElementById('chat-retailer-search');
        renderClientRetailerSelect(searchInput?.value || '');
    } catch {
        // Keep the UI usable even when thread fetch fails.
    }
}

async function loadRetailerThreads() {
    if (chatRole !== 'retailer') return;

    try {
        const res = await apiCall('chat/list_threads.php?role=retailer');
        if (res.status !== 'ok') return;

        const select = document.getElementById('chat-client-select');
        if (!select) return;

        const existingEmail = activeChatClientEmail;
        select.innerHTML = '<option value="">Select a client</option>';

        const modeLabel = document.getElementById('chat-mode-label');

        for (const thread of (res.data.threads || [])) {
            const email = String(thread.client_email || '').toLowerCase();
            const name = String(thread.client_name || 'Client').trim();
            if (!email) continue;
            const option = document.createElement('option');
            option.value = `${email}|${name}`;
            option.textContent = `${name} (${email})`;
            if (existingEmail && existingEmail === email) {
                option.selected = true;
            }
            select.appendChild(option);
        }

        if ((res.data.threads || []).length === 0) {
            toggleChatThread(false);
            if (modeLabel) {
                modeLabel.textContent = `Retailer mode: ${retailerVerification.shopName || 'Verified retailer'} - waiting for client messages.`;
            }
        }
    } catch {
        showSuccessMessage('Unable to load retailer chat threads right now.');
    }
}

async function loadChatMessages() {
    if (!activeChatRetailerId || !activeChatClientEmail) {
        toggleChatThread(false);
        return;
    }

    try {
        const query = new URLSearchParams({
            retailer_id: String(activeChatRetailerId),
            client_email: activeChatClientEmail,
        });

        const res = await apiCall(`chat/messages.php?${query.toString()}`);
        if (res.status !== 'ok') {
            showSuccessMessage(res.message || 'Failed to load chat messages.');
            return;
        }

        renderChatThread(res.data.messages || []);
        toggleChatThread(true);
        startChatAutoRefresh();
    } catch {
        showSuccessMessage('Unable to load chat messages.');
    }
}

function renderChatThread(messages) {
    const wrap = document.getElementById('chat-messages');
    if (!wrap) return;

    if (!messages || messages.length === 0) {
        wrap.innerHTML = '<div class="chat-empty-state">No messages yet. Start the conversation.</div>';
        return;
    }

    const myRole = chatRole === 'retailer' ? 'retailer' : 'client';
    wrap.innerHTML = messages.map(msg => {
        const own = String(msg.sender_role || '') === myRole;
        const ts = Number(msg.timestamp || 0);
        const time = ts ? new Date(ts).toLocaleString() : '';
        return `
            <div class="chat-msg ${own ? 'me' : 'other'}">
                <div>${escHtml(msg.message || '')}</div>
                <span class="chat-msg-meta">${escHtml(msg.sender_name || '')} ${time ? `• ${escHtml(time)}` : ''}</span>
            </div>
        `;
    }).join('');

    wrap.scrollTop = wrap.scrollHeight;
}

function startChatAutoRefresh() {
    clearChatTimer();
    chatRefreshTimer = setInterval(async () => {
        if (!activeChatRetailerId || !activeChatClientEmail) return;
        try {
            const query = new URLSearchParams({
                retailer_id: String(activeChatRetailerId),
                client_email: activeChatClientEmail,
            });
            const res = await apiCall(`chat/messages.php?${query.toString()}`);
            if (res.status === 'ok') {
                renderChatThread(res.data.messages || []);
            }
        } catch {
            // Ignore refresh errors to avoid noisy UI.
        }
    }, 6000);
}

async function sendChatMessage() {
    const input = document.getElementById('chat-input');
    if (!input) return;

    const message = input.value.trim();
    if (!message) return;

    if (!activeChatRetailerId || !activeChatClientEmail) {
        showSuccessMessage('Choose a chat participant first.');
        return;
    }

    const payload = {
        sender_role: chatRole === 'retailer' ? 'retailer' : 'client',
        retailer_id: activeChatRetailerId,
        client_name: chatRole === 'retailer' ? activeChatClientName : String(currentClient?.name || 'Client'),
        client_email: chatRole === 'retailer' ? activeChatClientEmail : String(currentClient?.email || '').toLowerCase(),
        message,
    };

    try {
        const res = await apiCall('chat/send.php', {
            method: 'POST',
            body: JSON.stringify(payload),
        });
        if (res.status !== 'ok') {
            showSuccessMessage(res.message || 'Failed to send message.');
            return;
        }

        input.value = '';
        await loadChatMessages();
        if (chatRole === 'retailer') {
            await loadRetailerThreads();
        } else {
            await loadClientThreads();
        }
    } catch {
        showSuccessMessage('Failed to send message.');
    }
}
