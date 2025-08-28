<?php
// Ensure session starts first, before any output
session_start();
error_log("sidebar.php: Starting script");

require_once 'config.php';
error_log("sidebar.php: Config loaded successfully");

// Only redirect if not on the login page
$current_path = $_SERVER['PHP_SELF'];
$is_login_page = preg_match('/\/index\.php$/', $current_path);
if (!isset($_SESSION['user_id']) && !$is_login_page) {
    error_log("sidebar.php: No user logged in, redirecting to index.php from path: $current_path");
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
error_log("sidebar.php: User ID set to: " . ($user_id ?: 'null'));

// Fetch categories with error handling, ensuring ID 5 (Inventory) is excluded
$categories = [];
try {
    $category_stmt = $mysqli->prepare('SELECT id, name FROM categories WHERE id NOT IN (1, 8, 5, 2, 3, 4) ORDER BY id');
    if ($category_stmt === false) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }
    $category_stmt->execute();
    $result = $category_stmt->get_result();
    $categories = $result->fetch_all(MYSQLI_ASSOC);
    $category_stmt->close();
    error_log("sidebar.php: Categories fetched successfully: " . json_encode($categories));
} catch (Exception $e) {
    error_log("sidebar.php: ERROR - Failed to fetch categories: " . $e->getMessage());
    $categories = []; // Fallback to empty array
}

if ($_SESSION['email'] === 'jason_high@hotmail.com') {
    $ordered_categories = array_merge([['id' => 14, 'name' => 'MANAGE', 'url' => 'category.php?id=14', 'submenu' => []]], $ordered_categories);
}

// Define the reordered menu with submenus for Roster and Inventory, avoiding duplicates
$ordered_categories = [
    ['id' => 14, 'name' => 'MANAGE', 'url' => 'category.php?id=14', 'submenu' => []], // Added MANAGE with ID 14
    ['id' => 0, 'name' => 'Home', 'url' => 'home.php', 'submenu' => []],
    ['id' => 1, 'name' => 'User Profile', 'url' => 'category.php', 'submenu' => []],
    ['id' => 8, 'name' => 'Roster', 'url' => 'category.php', 'submenu' => [
        ['id' => 8, 'name' => 'Contact Information', 'url' => 'contact_information.php'],
        ['id' => 5, 'name' => 'Accounts', 'url' => 'accounts.php']
    ]],
    ['id' => 5, 'name' => 'Inventory', 'url' => 'category.php', 'submenu' => [
        ['id' => 2, 'name' => 'Instrument Inventory', 'url' => 'category.php'],
        ['id' => 3, 'name' => 'Uniform Inventory', 'url' => 'category.php'],
        ['id' => 4, 'name' => 'Music Library', 'url' => 'category.php']
    ]]
];

// Append remaining categories, explicitly skipping ID 5
$existing_ids = array_column($ordered_categories, 'id');
foreach ($categories as $category) {
    if (!in_array($category['id'], $existing_ids) && $category['id'] != 5) {
        $ordered_categories[] = ['id' => $category['id'], 'name' => $category['name'], 'url' => 'category.php', 'submenu' => []];
    }
}

error_log("sidebar.php: Ordered categories prepared: " . json_encode($ordered_categories));

// Determine current page and ID for submenu state
$current_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$current_path = $_SERVER['PHP_SELF'];
$normalized_path = $current_path === '/' ? '/index.php' : $current_path;

// Initialize session for submenu states if not set
if (!isset($_SESSION['submenu_states'])) {
    $_SESSION['submenu_states'] = [];
}

// Session cleanup
foreach ($_SESSION as $key => $value) {
    if ((strpos($key, 'sidebar_rendered_') === 0 || strpos($key, 'page_rendered_') === 0) && $value === true) {
        unset($_SESSION[$key]);
    }
}
error_log("sidebar.php: Session size after cleanup: " . strlen(serialize($_SESSION)));
?>

<aside class="bg-blue-600 text-white">
    <div class="p-4">
        <h1 class="text-2xl font-bold">FireSafe</h1>
    </div>
    <nav>
        <ul>
            <?php foreach ($ordered_categories as $category): ?>
                <li>
                    <?php if (!empty($category['submenu'])): ?>
                        <div class="relative">
                            <?php
                            $submenu_open = false;
                            $submenu_href = $category['url'] . '?id=' . $category['id'];
                            $session_key = 'submenu_' . md5($submenu_href);
                            foreach ($category['submenu'] as $submenu_item) {
                                $submenu_item_href = $submenu_item['url'] . '?id=' . $submenu_item['id'];
                                $submenu_path = '/' . $submenu_item['url'];
                                if ($submenu_item['id'] == $current_id && ($normalized_path == $submenu_path || ($submenu_path == '/category.php' && $normalized_path == '/category.php'))) {
                                    $submenu_open = true;
                                    $_SESSION['submenu_states'][$session_key] = 'open';
                                    break;
                                }
                            }
                            if ($category['id'] == $current_id && $normalized_path == '/category.php') {
                                $submenu_open = true;
                                $_SESSION['submenu_states'][$session_key] = 'open';
                            }
                            if (!$submenu_open && isset($_SESSION['submenu_states'][$session_key]) && $_SESSION['submenu_states'][$session_key] === 'open') {
                                $submenu_open = true;
                            }
                            ?>
                            <a data-href="<?php echo htmlspecialchars($category['url'] . ($category['id'] != 0 ? '?id=' . $category['id'] : '')); ?>" class="block p-4 hover:bg-blue-700 flex justify-between items-center <?php echo ($current_id == $category['id'] && $normalized_path == '/category.php') ? 'bg-blue-700' : ''; ?>" onclick="navigateTo(this, event, '<?php echo $category['id']; ?>', true)">
                                <span><?php echo htmlspecialchars($category['name']); ?></span>
                                <span class="arrow <?php echo $submenu_open ? 'open' : ''; ?>">▼</span>
                            </a>
                            <ul class="submenu <?php echo $submenu_open ? '' : 'hidden'; ?> bg-blue-500" data-id="<?php echo $category['id']; ?>">
                                <?php foreach ($category['submenu'] as $submenu_item): ?>
                                    <li>
                                        <a data-href="<?php echo htmlspecialchars($submenu_item['url'] . '?id=' . $submenu_item['id']); ?>" class="block p-4 pl-8 hover:bg-blue-700 <?php echo ($current_id == $submenu_item['id'] && ($normalized_path == '/' . $submenu_item['url'] || ($submenu_item['url'] == 'category.php' && $normalized_path == '/category.php'))) ? 'bg-blue-700' : ''; ?>" onclick="navigateTo(this, event, '<?php echo $submenu_item['id']; ?>', false)">
                                            <?php echo htmlspecialchars($submenu_item['name']); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <?php if ($category['id'] == 0): ?>
                            <div id="home-link-container">
                                <a href="<?php echo htmlspecialchars($category['url'] . '?t=' . time()); ?>" class="block p-4 hover:bg-blue-700 <?php echo $normalized_path == '/home.php' ? 'bg-blue-700' : ''; ?>" id="home-link">
                                    Home
                                </a>
                            </div>
                        <?php else: ?>
                            <a data-href="<?php echo htmlspecialchars($category['url'] . ($category['id'] != 0 ? '?id=' . $category['id'] : '')); ?>" class="block p-4 hover:bg-blue-700 <?php echo ($current_id == $category['id'] && $normalized_path == '/category.php') ? 'bg-blue-700' : ''; ?>" onclick="navigateTo(this, event, '<?php echo $category['id']; ?>', false)">
                                <?php echo htmlspecialchars($category['name']); ?>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            <li>
                <a href="logout.php" class="block p-4 hover:bg-blue-700">Logout</a>
            </li>
        </ul>
    </nav>
</aside>

<style>
.submenu {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease;
}
.submenu:not(.hidden) {
    max-height: 500px; /* Adjust based on submenu content */
}
.arrow {
    transition: transform 0.3s ease;
}
.arrow.open {
    transform: rotate(-180deg);
}
#home-link-container, #home-link, nav a[data-href] {
    color: white !important;
    background-color: #2563eb !important; /* bg-blue-600 */
    display: block !important;
    min-height: 3rem;
    pointer-events: auto !important;
}
#home-link {
    visibility: visible !important;
}
</style>

<script>
let isNavigating = false;
let lastHomeLinkState = null;
let lastClickTime = 0;
const clickDebounceDelay = 500;

function logError(message, error) {
    console.error(`[FireSafe Error] ${message}`, error || '');
}

function toggleSubmenu(element) {
    try {
        const submenu = element.nextElementSibling;
        const arrow = element.querySelector('.arrow');
        if (!submenu || !submenu.classList.contains('submenu')) {
            console.error('Submenu not found for element:', element.getAttribute('data-href'));
            return false;
        }
        const href = element.getAttribute('data-href');
        const isHidden = submenu.classList.contains('hidden');

        console.log(`toggleSubmenu called for ${href}: isHidden=${isHidden}`);

        if (isHidden) {
            submenu.classList.remove('hidden');
            arrow.classList.add('open');
            localStorage.setItem('submenu_' + href, 'open');
            console.log(`Submenu opened: ${href}`);
        } else {
            submenu.classList.remove('hidden'); // Prevent immediate collapse
            arrow.classList.add('open');
            localStorage.setItem('submenu_' + href, 'open');
            console.log(`Submenu kept open: ${href}`);
        }
        return true;
    } catch (e) {
        logError('Error in toggleSubmenu', e);
        return false;
    }
}

function collapseOtherSubmenus(currentElement) {
    try {
        document.querySelectorAll('.submenu').forEach(submenu => {
            const parentLink = submenu.previousElementSibling;
            if (parentLink !== currentElement) {
                submenu.classList.add('hidden');
                parentLink.querySelector('.arrow').classList.remove('open');
                localStorage.setItem('submenu_' + parentLink.getAttribute('data-href'), 'closed');
                console.log(`Collapsed other submenu: ${parentLink.getAttribute('data-href')}`);
            } else if (currentElement) {
                // Keep current submenu open
                submenu.classList.remove('hidden');
                parentLink.querySelector('.arrow').classList.add('open');
                localStorage.setItem('submenu_' + currentElement.getAttribute('data-href'), 'open');
                console.log(`Preserved current submenu: ${currentElement.getAttribute('data-href')}`);
            }
        });
    } catch (e) {
        logError('Error in collapseOtherSubmenus', e);
    }
}

function ensureHomeLink() {
    try {
        let homeContainer = document.getElementById('home-link-container');
        let homeLink = document.getElementById('home-link');
        
        if (!homeContainer) {
            console.warn('Home link container missing, restoring...');
            const navUl = document.querySelector('nav ul');
            if (navUl) {
                const homeLi = document.createElement('li');
                homeContainer = document.createElement('div');
                homeContainer.id = 'home-link-container';
                homeLink = document.createElement('a');
                homeLink.id = 'home-link';
                homeLink.href = 'home.php?t=' + new Date().getTime();
                homeLink.className = 'block p-4 hover:bg-blue-700' + (window.location.pathname === '/home.php' ? ' bg-blue-700' : '');
                homeLink.textContent = 'Home';
                homeContainer.appendChild(homeLink);
                homeLi.appendChild(homeContainer);
                navUl.insertBefore(homeLi, navUl.firstChild);
                console.log('Restored Home link container and link');
            } else {
                logError('Cannot restore Home link: nav ul not found');
                window.location.reload();
                return;
            }
        }

        if (!homeLink) {
            console.warn('Home link missing from container, restoring...');
            homeLink = document.createElement('a');
            homeLink.id = 'home-link';
            homeLink.href = 'home.php?t=' + new Date().getTime();
            homeLink.className = 'block p-4 hover:bg-blue-700' + (window.location.pathname === '/home.php' ? ' bg-blue-700' : '');
            homeLink.textContent = 'Home';
            homeContainer.appendChild(homeLink);
            console.log('Restored Home link in container');
        }

        if (!homeLink.textContent.trim()) {
            console.warn('Home link text missing, restoring...');
            homeLink.textContent = 'Home';
        }
        if (!homeLink.classList.contains('hover:bg-blue-700')) {
            console.warn('Home link missing hover class, restoring...');
            homeLink.classList.add('hover:bg-blue-700');
        }
        if (!homeLink.getAttribute('href') || !homeLink.getAttribute('href').includes('home.php')) {
            console.warn('Home link href invalid, restoring...');
            homeLink.setAttribute('href', 'home.php?t=' + new Date().getTime());
        }
        if (window.location.pathname === '/home.php') {
            homeLink.classList.add('bg-blue-700');
        } else {
            homeLink.classList.remove('bg-blue-700');
        }

        const parentLi = homeContainer.parentElement;
        if (parentLi && getComputedStyle(parentLi).backgroundColor !== 'rgb(37, 99, 235)') {
            console.warn('Home link parent LI has incorrect background, fixing...');
            parentLi.style.backgroundColor = '#2563eb';
        }

        const currentState = {
            text: homeLink.textContent,
            classes: homeLink.className,
            href: homeLink.getAttribute('href'),
            display: window.getComputedStyle(homeLink).display,
            background: getComputedStyle(homeLink).backgroundColor,
            parentBackground: parentLi ? getComputedStyle(parentLi).backgroundColor : 'N/A'
        };

        if (JSON.stringify(currentState) !== JSON.stringify(lastHomeLinkState)) {
            console.log('Home link state changed:', currentState);
            lastHomeLinkState = currentState;
        }
    } catch (e) {
        logError('Error in ensureHomeLink', e);
    }
}

function startHomeLinkMonitor() {
    setInterval(ensureHomeLink, 500);
}

function updateActiveLinks(categoryId, hrefPath) {
    try {
        document.querySelectorAll('nav a[data-href]:not(#home-link)').forEach(link => {
            link.classList.remove('bg-blue-700');
            const linkId = new URLSearchParams(link.getAttribute('data-href').split('?')[1] || '').get('id') || '0';
            const linkPath = '/' + link.getAttribute('data-href').split('?')[0].split('/').pop();
            const isSubmenuItem = link.closest('.submenu');

            if (isSubmenuItem) {
                if (linkId === categoryId && (hrefPath === linkPath || (hrefPath === '/category.php' && linkPath === '/category.php'))) {
                    link.classList.add('bg-blue-700');
                }
            } else {
                if (linkId === categoryId && hrefPath === linkPath && hrefPath === '/category.php') {
                    link.classList.add('bg-blue-700');
                }
            }
        });

        ensureHomeLink();
    } catch (e) {
        logError('Error in updateActiveLinks', e);
    }
}

function navigateTo(element, event, categoryId, hasSubmenu) {
    event.preventDefault();
    event.stopPropagation();

    const now = Date.now();
    if (now - lastClickTime < clickDebounceDelay) {
        console.log('Click debounced, ignoring');
        return;
    }
    lastClickTime = now;

    if (isNavigating) {
        console.log('Navigation in progress, ignoring click');
        return;
    }

    isNavigating = true;

    try {
        let href = element.getAttribute('data-href');
        const currentId = new URLSearchParams(window.location.search).get('id') || '0';
        const currentPath = window.location.pathname;
        const hrefPath = '/' + href.split('?')[0].split('/').pop();
        const targetId = categoryId;

        const normalizedCurrentPath = currentPath === '/' ? '/index.php' : currentPath;
        const normalizedHrefPath = hrefPath === '/' ? '/index.php' : hrefPath;

        console.log('navigateTo:', {
            currentId: currentId,
            targetId: targetId,
            currentPath: normalizedCurrentPath,
            hrefPath: normalizedHrefPath,
            hasSubmenu: hasSubmenu,
            href: href
        });

        const isSubmenuItem = element.closest('.submenu');
        const parentLink = isSubmenuItem ? element.closest('.submenu').previousElementSibling : null;

        if (!hasSubmenu && !isSubmenuItem) {
            collapseOtherSubmenus(null);
        } else if (hasSubmenu) {
            const toggled = toggleSubmenu(element);
            if (toggled) {
                collapseOtherSubmenus(element);
            }
        } else if (isSubmenuItem) {
            // Keep parent submenu open
            if (parentLink) {
                const parentSubmenu = parentLink.nextElementSibling;
                const parentArrow = parentLink.querySelector('.arrow');
                parentSubmenu.classList.remove('hidden');
                parentArrow.classList.add('open');
                localStorage.setItem('submenu_' + parentLink.getAttribute('data-href'), 'open');
                console.log(`Kept parent submenu open: ${parentLink.getAttribute('data-href')}`);
            }
            collapseOtherSubmenus(parentLink);
        }

        const disableAjax = (targetId === '8' && hrefPath === '/category.php') || 
                           (targetId === '8' && hrefPath === '/contact_information.php') || 
                           (['2', '3', '4'].includes(targetId) && hrefPath === '/category.php' && currentId === '5');

        if (disableAjax) {
            console.log('Disabling AJAX for page, performing full reload:', href);
            window.location.href = href;
            isNavigating = false;
            return;
        }

        fetch(href, { cache: 'no-store', signal: AbortSignal.timeout(5000) })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.text();
            })
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');

                const newContent = doc.querySelector('.content-wrapper');
                if (!newContent) {
                    throw new Error('Could not find .content-wrapper in the fetched content');
                }

                const currentContent = document.querySelector('.content-wrapper');
                if (currentContent) {
                    currentContent.innerHTML = newContent.innerHTML;
                }

                const newFixedBar = doc.querySelector('.fixed-bar');
                const currentFixedBar = document.querySelector('.fixed-bar');
                const navBar = document.querySelector('nav');
                if (newFixedBar && currentFixedBar) {
                    currentFixedBar.outerHTML = newFixedBar.outerHTML;
                    console.log('Replaced existing fixed-bar');
                } else if (currentFixedBar && !newFixedBar) {
                    currentFixedBar.remove();
                    console.log('Removed fixed-bar');
                } else if (newFixedBar && !currentFixedBar) {
                    if (navBar) {
                        navBar.insertAdjacentElement('afterend', newFixedBar);
                        console.log('Added new fixed-bar after nav bar');
                    } else {
                        document.body.prepend(newFixedBar);
                        console.log('Added new fixed-bar at the top of body (no nav bar found)');
                    }
                }

                reinitializeEventListeners();

                history.pushState({}, '', href);

                updateActiveLinks(categoryId, hrefPath);
                ensureHomeLink();

                isNavigating = false;
            })
            .catch(error => {
                logError('Error fetching content', error);
                window.location.href = href;
                isNavigating = false;
            });
    } catch (e) {
        logError('Error in navigateTo', e);
        isNavigating = false;
    }
}

function reinitializeEventListeners() {
    try {
        const searchInput = document.getElementById('tableSearch');
        if (searchInput) {
            searchInput.addEventListener('input', () => {
                const searchTerm = searchInput.value.toLowerCase();
                const pageCategoryId = new URLSearchParams(window.location.search).get('id');
                const tableId = pageCategoryId === '2' ? 'instrumentTable' : (pageCategoryId === '3' ? 'uniformTable' : (pageCategoryId === '4' ? 'musicTable' : 'rosterTable'));
                const table = document.getElementById(tableId);
                if (!table) {
                    console.error(`Table with ID ${tableId} not found for search`);
                    return;
                }
                const rows = table.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    let rowContainsSearchTerm = false;
                    cells.forEach(cell => {
                        if (cell.classList.contains('instrument-col-reorder') || 
                            cell.classList.contains('instrument-col-checkbox') ||
                            cell.classList.contains('uniform-col-reorder') || 
                            cell.classList.contains('uniform-col-checkbox') ||
                            cell.classList.contains('music-col-reorder') || 
                            cell.classList.contains('music-col-checkbox') ||
                            cell.classList.contains('roster-col-actions')) {
                            return;
                        }
                        if (cell.classList.contains('instrument-col-actions') ||
                            cell.classList.contains('uniform-col-actions') ||
                            cell.classList.contains('music-col-actions')) {
                            return;
                        }
                        const cellText = cell.textContent.toLowerCase();
                        if (cellText.includes(searchTerm)) {
                            rowContainsSearchTerm = true;
                        }
                    });
                    row.style.display = rowContainsSearchTerm || searchTerm === '' ? 'table-row' : 'none';
                });
                console.log(`Filtered table ${tableId} with search term: ${searchTerm}`);
            });
        }

        document.querySelectorAll('button[onclick^="openFormModal"]').forEach(button => {
            const onclickAttr = button.getAttribute('onclick');
            button.onclick = null;
            button.addEventListener('click', () => {
                eval(onclickAttr);
            });
        });

        document.querySelectorAll('button[onclick^="openImportModal"]').forEach(button => {
            const onclickAttr = button.getAttribute('onclick');
            button.onclick = null;
            button.addEventListener('click', () => {
                eval(onclickAttr);
            });
        });

        if (typeof initializeEventListeners === 'function') {
            initializeEventListeners();
        }
    } catch (e) {
        logError('Error in reinitializeEventListeners', e);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    try {
        const currentId = new URLSearchParams(window.location.search).get('id') || '0';
        const currentPath = window.location.pathname;
        const normalizedCurrentPath = currentPath === '/' ? '/index.php' : currentPath;

        // Initialize submenu states
        document.querySelectorAll('a[data-href*="category.php?id="]').forEach(link => {
            const submenu = link.nextElementSibling;
            if (submenu && submenu.classList.contains('submenu')) {
                const href = link.getAttribute('data-href');
                const state = localStorage.getItem('submenu_' + href);
                const arrow = link.querySelector('.arrow');
                const submenuId = submenu.getAttribute('data-id');
                const hasActiveLink = Array.from(submenu.querySelectorAll('a[data-href]')).some(submenuLink => {
                    const linkId = new URLSearchParams(submenuLink.getAttribute('data-href').split('?')[1] || '').get('id');
                    const linkPath = '/' + submenuLink.getAttribute('data-href').split('?')[0].split('/').pop();
                    return linkId === currentId && (normalizedCurrentPath === linkPath || (linkPath === '/category.php' && normalizedCurrentPath === '/category.php'));
                }) || (currentId === submenuId && normalizedCurrentPath === '/category.php');
                console.log(`Submenu init for ${href}: localStorage=${state}, hasActiveLink=${!!hasActiveLink}, submenuId=${submenuId}, currentId=${currentId}`);

                if (hasActiveLink) {
                    submenu.classList.remove('hidden');
                    arrow.classList.add('open');
                    localStorage.setItem('submenu_' + href, 'open');
                    console.log(`Initialized submenu open due to active link: ${href}`);
                } else if (state === 'open') {
                    submenu.classList.remove('hidden');
                    arrow.classList.add('open');
                    localStorage.setItem('submenu_' + href, 'open');
                    console.log(`Initialized submenu open per localStorage: ${href}`);
                } else {
                    submenu.classList.add('hidden');
                    arrow.classList.remove('open');
                    localStorage.setItem('submenu_' + href, 'closed');
                    console.log(`Initialized submenu closed: ${href}`);
                }
            }
        });

        updateActiveLinks(currentId, normalizedCurrentPath);
        ensureHomeLink();
        startHomeLinkMonitor();
    } catch (e) {
        logError('Error in DOMContentLoaded', e);
    }
});

window.addEventListener('popstate', () => {
    try {
        const href = window.location.href;
        fetch(href, { cache: 'no-store', signal: AbortSignal.timeout(5000) })
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newContent = doc.querySelector('.content-wrapper');
                const currentContent = document.querySelector('.content-wrapper');
                if (newContent && currentContent) {
                    currentContent.innerHTML = newContent.innerHTML;
                }

                const newFixedBar = doc.querySelector('.fixed-bar');
                const currentFixedBar = document.querySelector('.fixed-bar');
                const navBar = document.querySelector('nav');
                if (newFixedBar && currentFixedBar) {
                    currentFixedBar.outerHTML = newFixedBar.outerHTML;
                    console.log('Replaced existing fixed-bar on popstate');
                } else if (currentFixedBar && !newFixedBar) {
                    currentFixedBar.remove();
                    console.log('Removed fixed-bar on popstate');
                } else if (newFixedBar && !currentFixedBar) {
                    if (navBar) {
                        navBar.insertAdjacentElement('afterend', newFixedBar);
                        console.log('Added new fixed-bar after nav bar on popstate');
                    } else {
                        document.body.prepend(newFixedBar);
                        console.log('Added new fixed-bar at the top of body on popstate (no nav bar found)');
                    }
                }

                reinitializeEventListeners();

                const currentId = new URLSearchParams(window.location.search).get('id') || '0';
                const currentPath = window.location.pathname;
                const normalizedCurrentPath = currentPath === '/' ? '/index.php' : currentPath;
                updateActiveLinks(currentId, normalizedCurrentPath);
                ensureHomeLink();

                // Respect localStorage on popstate
                document.querySelectorAll('a[data-href*="category.php?id="]').forEach(link => {
                    const submenu = link.nextElementSibling;
                    if (submenu && submenu.classList.contains('submenu')) {
                        const href = link.getAttribute('data-href');
                        const state = localStorage.getItem('submenu_' + href);
                        const arrow = link.querySelector('.arrow');
                        const submenuId = submenu.getAttribute('data-id');
                        const hasActiveLink = Array.from(submenu.querySelectorAll('a[data-href]')).some(submenuLink => {
                            const linkId = new URLSearchParams(submenuLink.getAttribute('data-href').split('?')[1] || '').get('id');
                            const linkPath = '/' + submenuLink.getAttribute('data-href').split('?')[0].split('/').pop();
                            return linkId === currentId && (normalizedCurrentPath === linkPath || (linkPath === '/category.php' && normalizedCurrentPath === '/category.php'));
                        }) || (currentId === submenuId && normalizedCurrentPath === '/category.php');
                        console.log(`Popstate submenu state for ${href}: localStorage=${state}, hasActiveLink=${!!hasActiveLink}, submenuId=${submenuId}, currentId=${currentId}`);
                        if (hasActiveLink) {
                            submenu.classList.remove('hidden');
                            arrow.classList.add('open');
                            localStorage.setItem('submenu_' + href, 'open');
                            console.log(`Forced submenu open due to active link on popstate: ${href}`);
                        } else if (state === 'open') {
                            submenu.classList.remove('hidden');
                            arrow.classList.add('open');
                            localStorage.setItem('submenu_' + href, 'open');
                            console.log(`Kept submenu open per localStorage on popstate: ${href}`);
                        } else {
                            submenu.classList.add('hidden');
                            arrow.classList.remove('open');
                            localStorage.setItem('submenu_' + href, 'closed');
                            console.log(`Kept submenu closed per localStorage on popstate: ${href}`);
                        }
                    }
                });
            })
            .catch(error => {
                logError('Error handling popstate', error);
                window.location.reload();
            });
    } catch (e) {
        logError('Error in popstate handler', e);
    }
});
</script>

<?php
error_log("sidebar.php: Script completed");
?>
