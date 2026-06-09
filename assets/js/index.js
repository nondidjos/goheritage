// GoHéritage — global entry point
// handles mobile menu toggle, compare slider, procédé steps

document.addEventListener('DOMContentLoaded', function () {
    // mobile hamburger menu
    const toggle = document.getElementById('mobile-menu-toggle');
    const nav = document.getElementById('site-nav');
    const closeBtn = document.getElementById('mobile-menu-close');

    function closeMenu() {
        toggle.setAttribute('aria-expanded', 'false');
        nav.classList.remove('is-open');
        document.body.classList.remove('nav-open');
    }

    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!expanded));
            nav.classList.toggle('is-open');
            document.body.classList.toggle('nav-open');
        });

        // Close menu when clicking a link
        nav.querySelectorAll('a').forEach(function (link) {
            link.addEventListener('click', closeMenu);
        });

        // Close menu when clicking the close button
        if (closeBtn) {
            closeBtn.addEventListener('click', closeMenu);
        }

        // Close menu when clicking the overlay
        document.addEventListener('click', function (e) {
            if (nav.classList.contains('is-open') && !nav.contains(e.target) && !toggle.contains(e.target)) {
                closeMenu();
            }
        });
    }

    // ── Project page: mobile content drawer (drag up/down or tap) ──
    var projectDrawerHandle = document.getElementById('project-drawer-handle');
    var projectContent = document.getElementById('project-content');
    if (projectDrawerHandle && projectContent) {
        var DRAWER_PEEK = 56;            // px of the drawer left peeking when collapsed
        var dragging = false, moved = false;
        var startY = 0, startTranslate = 0;

        // Distance the drawer is pushed down when collapsed (height − peek).
        function collapsedTranslate() {
            return Math.max(0, projectContent.getBoundingClientRect().height - DRAWER_PEEK);
        }
        function currentTranslate() {
            return projectContent.classList.contains('is-expanded') ? 0 : collapsedTranslate();
        }
        function setExpanded(on) {
            projectContent.classList.toggle('is-expanded', on);
            document.body.style.overflow = on ? 'hidden' : '';
        }

        function onDown(e) {
            dragging = true;
            moved = false;
            startY = e.clientY;
            startTranslate = currentTranslate();
            projectContent.classList.add('is-dragging');
            projectDrawerHandle.classList.add('is-dragging');
            // Capture so the whole drag streams to the handle even when the
            // finger leaves it — without this, mobile browsers steal the
            // gesture for scrolling and fire pointercancel (no drag at all).
            try { projectDrawerHandle.setPointerCapture(e.pointerId); } catch (_) {}
        }
        function onMove(e) {
            if (!dragging) return;
            var dy = e.clientY - startY;
            if (Math.abs(dy) > 4) moved = true;
            var t = Math.max(0, Math.min(collapsedTranslate(), startTranslate + dy));
            projectContent.style.transform = 'translateY(' + t + 'px)';
        }
        function onUp(e) {
            if (!dragging) return;
            dragging = false;
            projectDrawerHandle.classList.remove('is-dragging');

            var dy = (e.clientY || startY) - startY;
            var willExpand;
            if (!moved) {
                // Treat as a tap → toggle.
                willExpand = !projectContent.classList.contains('is-expanded');
            } else {
                var threshold = collapsedTranslate() * 0.3;
                willExpand = projectContent.classList.contains('is-expanded')
                    ? !(dy > threshold)      // was open: close only if dragged far down
                    : (-dy > threshold);     // was closed: open only if dragged far up
            }
            // Restore CSS transition, set final state, hand transform back to CSS.
            projectContent.classList.remove('is-dragging');
            projectContent.style.transform = '';
            setExpanded(willExpand);
        }

        projectDrawerHandle.addEventListener('pointerdown', onDown);
        document.addEventListener('pointermove', onMove, { passive: true });
        document.addEventListener('pointerup', onUp);
        document.addEventListener('pointercancel', onUp);

        // Tapping the viewer area collapses the drawer
        var viewerContainer = document.getElementById('viewer-container');
        if (viewerContainer) {
            viewerContainer.addEventListener('click', function (e) {
                // Don't collapse if clicking a label or control inside the viewer
                if (e.target.closest('.viewer-label, .viewer-toggle')) return;
                if (projectContent.classList.contains('is-expanded')) {
                    setExpanded(false);
                }
            });
        }
    }

    // ── Scroll hijacking: wheel over the project sidebar stays in the sidebar ──
    // Prevents wheel events from bubbling up to the page when hovering the
    // project content panel. The native scrollbar drag (no wheel event) is
    // still usable to jump directly to the footer.
    var projectSidebar = document.getElementById('project-content');
    if (projectSidebar) {
        projectSidebar.addEventListener('wheel', function (e) {
            e.preventDefault();
            var delta = e.deltaY;
            if (e.deltaMode === 1) delta *= 40;                      // line → px
            if (e.deltaMode === 2) delta *= projectSidebar.clientHeight; // page → px
            projectSidebar.scrollTop += delta;
        }, { passive: false });
    }

    // ── Gallery lightbox ──
    document.querySelectorAll('[data-lightbox]').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var src = link.getAttribute('href');
            var alt = link.querySelector('img') ? link.querySelector('img').alt : '';
            if (typeof basicLightbox !== 'undefined') {
                var instance = basicLightbox.create('<img src="' + src + '" alt="' + alt + '">');
                instance.show();

                // Close on Escape key
                function onKey(ev) {
                    if (ev.key === 'Escape' && instance.visible()) {
                        instance.close();
                    }
                }
                document.addEventListener('keydown', onKey);
                // Clean up listener when lightbox closes
                var origClose = instance.close.bind(instance);
                instance.close = function () {
                    document.removeEventListener('keydown', onKey);
                    return origClose();
                };
            }
        });
    });

    // compare sliders — works for all instances on the page
    document.querySelectorAll('.compare-slider').forEach(function (slider) {
        var afterEl = slider.querySelector('.compare-slider__after');
        var handle = slider.querySelector('.compare-slider__handle');
        var dragging = false;

        function updatePosition(x) {
            var rect = slider.getBoundingClientRect();
            var pct = ((x - rect.left) / rect.width) * 100;
            pct = Math.max(0, Math.min(100, pct));
            afterEl.style.clipPath = 'inset(0 0 0 ' + pct + '%)';
            handle.style.left = pct + '%';
        }

        function startDrag(x) {
            dragging = true;
            slider.classList.add('is-dragging');
            updatePosition(x);
            document.body.style.cursor = 'col-resize';
        }

        function moveDrag(x) {
            if (!dragging) return;
            updatePosition(x);
        }

        function stopDrag() {
            if (!dragging) return;
            dragging = false;
            slider.classList.remove('is-dragging');
            document.body.style.cursor = '';
        }

        // mouse
        slider.addEventListener('mousedown', function (e) {
            e.preventDefault();
            startDrag(e.clientX);
        });
        document.addEventListener('mousemove', function (e) {
            moveDrag(e.clientX);
        });
        document.addEventListener('mouseup', stopDrag);

        // touch
        slider.addEventListener('touchstart', function (e) {
            startDrag(e.touches[0].clientX);
        }, { passive: true });
        document.addEventListener('touchmove', function (e) {
            moveDrag(e.touches[0].clientX);
        }, { passive: true });
        document.addEventListener('touchend', stopDrag);
    });

    // procédé interactive steps — absolute positioned with left transition
    var stepsContainer = document.getElementById('procede-steps');
    var imageContainer = document.getElementById('procede-images');

    if (stepsContainer && imageContainer) {
        var steps = stepsContainer.querySelectorAll('[data-step]');
        var images = imageContainer.querySelectorAll('.procede-image');
        var progressBar = document.getElementById('procede-progress');
        var isMobileSteps = window.innerWidth <= 640;

        var positions = [
            ['0%', '42%', '72%'],   // step 0 active
            ['0%', '29%', '72%'],   // step 1 active
            ['0%', '29%', '58%'],   // step 2 active
        ];

        var duration = 5000; // 5 seconds per step
        var progress = 0;
        var lastTime = performance.now();
        var isPaused = false;
        var currentStepIdx = 0;
        var autoPlayReq;

        function renderStep(idx) {
            currentStepIdx = idx;
            isMobileSteps = window.innerWidth <= 640;
            // crossfade images
            images.forEach(function (img) {
                img.classList.toggle('is-hidden', parseInt(img.dataset.step) !== idx);
            });

            // slide cards + update number colors + mobile active class
            steps.forEach(function (s, i) {
                var isActive = parseInt(s.dataset.step) === idx;
                var num = s.querySelector('span');
                if (num) {
                    num.className = num.className
                        .replace(/text-(ink|faint)/g, '')
                        .trim() + (isActive ? ' text-ink' : ' text-faint');
                }
                s.classList.toggle('is-active', isActive);
                if (!isMobileSteps) s.style.left = positions[idx][i];
            });
        }

        function tick(time) {
            var dt = time - lastTime;
            lastTime = time;

            if (!isPaused) {
                progress += dt;
                if (progress >= duration) {
                    progress = 0;
                    currentStepIdx = (currentStepIdx + 1) % steps.length;
                    renderStep(currentStepIdx);
                }
                // Update progress bar width visually
                if (progressBar) {
                    progressBar.style.width = (progress / duration * 100) + '%';
                }
            }
            autoPlayReq = requestAnimationFrame(tick);
        }

        // Click on a step jumps to it and resets progress
        steps.forEach(function (step) {
            step.addEventListener('click', function () {
                var idx = parseInt(step.dataset.step);
                progress = 0; // reset timer for new step
                renderStep(idx);
            });
        });

        // Pause only when hovering the step cards
        function pause() { isPaused = true; }
        function play() { isPaused = false; lastTime = performance.now(); }

        stepsContainer.addEventListener('mouseenter', pause);
        stepsContainer.addEventListener('mouseleave', play);

        // Initialize first step and animation loop
        renderStep(0);
        autoPlayReq = requestAnimationFrame(tick);
    }

    // ── Blog: search drawer (mobile) ──
    var blogSearchDrawer = document.getElementById('blog-search-drawer');
    var blogSearchHandle = document.getElementById('blog-search-handle');
    if (blogSearchDrawer && blogSearchHandle) {
        function toggleBlogDrawer(e) {
            blogSearchDrawer.classList.toggle('is-expanded');
        }
        blogSearchHandle.addEventListener('click', toggleBlogDrawer);

        // Tapping the search bar itself expands when collapsed
        var blogSearchBar = blogSearchDrawer.querySelector('.blog-search-bar');
        if (blogSearchBar) {
            blogSearchBar.addEventListener('click', function (e) {
                if (!blogSearchDrawer.classList.contains('is-expanded')) {
                    e.preventDefault();
                    blogSearchDrawer.classList.add('is-expanded');
                }
            });
        }
    }

    // ── Blog: client-side tag + author filtering ──
    var tagBtns     = document.querySelectorAll('[data-filter-tag]');
    var authorBtns  = document.querySelectorAll('#blog-author-filters [data-filter-author]');
    var bylineBtns  = document.querySelectorAll('[data-article-author] ~ * [data-filter-author], .byline[data-filter-author]');
    var blogArticles = document.querySelectorAll('[data-article-tags]');
    var clearBtn    = document.getElementById('blog-clear-filters');
    var noResults   = document.getElementById('blog-no-results');

    if (blogArticles.length) {
        var selectedBlogTags    = new Set();
        var selectedBlogAuthors = new Set();

        // ── Sync sidebar author button visual state ──
        function syncAuthorBtns() {
            authorBtns.forEach(function (btn) {
                btn.classList.toggle('tag--active', selectedBlogAuthors.has(btn.dataset.filterAuthor));
            });
            // also highlight byline buttons in article list
            document.querySelectorAll('button.byline[data-filter-author]').forEach(function (btn) {
                btn.classList.toggle('byline--active', selectedBlogAuthors.has(btn.dataset.filterAuthor));
            });
        }

        // ── Apply filters ──
        function applyBlogFilters() {
            var hasTags    = selectedBlogTags.size > 0;
            var hasAuthors = selectedBlogAuthors.size > 0;
            var visibleCount = 0;

            blogArticles.forEach(function (article) {
                var show = true;
                if (hasTags) {
                    var tags = JSON.parse(article.dataset.articleTags || '[]');
                    if (!tags.some(function (t) { return selectedBlogTags.has(t.trim()); })) show = false;
                }
                if (hasAuthors && show) {
                    if (!selectedBlogAuthors.has(article.dataset.articleAuthor)) show = false;
                }
                article.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });

            if (clearBtn) clearBtn.classList.toggle('hidden!', !hasTags && !hasAuthors);
            if (noResults) noResults.classList.toggle('hidden', (!hasTags && !hasAuthors) || visibleCount > 0);
            syncAuthorBtns();
        }

        // ── Tag filter buttons ──
        tagBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tag = btn.dataset.filterTag;
                selectedBlogTags.has(tag) ? selectedBlogTags.delete(tag) : selectedBlogTags.add(tag);
                btn.classList.toggle('tag--active', selectedBlogTags.has(tag));
                applyBlogFilters();
            });
        });

        // ── Author filter buttons (sidebar + bylines on cards) ──
        function handleAuthorClick(author) {
            selectedBlogAuthors.has(author) ? selectedBlogAuthors.delete(author) : selectedBlogAuthors.add(author);
            applyBlogFilters();
        }

        authorBtns.forEach(function (btn) {
            btn.addEventListener('click', function () { handleAuthorClick(btn.dataset.filterAuthor); });
        });

        // Delegate byline clicks (covers flagship, sidebar, and main-list bylines)
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('button.byline[data-filter-author]');
            if (!btn) return;
            handleAuthorClick(btn.dataset.filterAuthor);
        });

        // ── Clear all filters ──
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                selectedBlogTags.clear();
                selectedBlogAuthors.clear();
                tagBtns.forEach(function (b) { b.classList.remove('tag--active'); });
                applyBlogFilters();
            });
        }

        // ── Pre-activate tag from URL ?tag= param ──
        var urlTag = new URLSearchParams(window.location.search).get('tag');
        if (urlTag) {
            var normalised = urlTag.trim();
            tagBtns.forEach(function (btn) {
                if (btn.dataset.filterTag.trim() === normalised) {
                    selectedBlogTags.add(normalised);
                    btn.classList.add('tag--active');
                }
            });
            setTimeout(function () {
                applyBlogFilters();
                var filtersEl = document.getElementById('blog-main-list');
                if (filtersEl) filtersEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }
    }

    // ── Blog: expand +N tag overflow ──
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.tag--overflow-toggle');
        if (!btn) return;
        var overflow = btn.previousElementSibling;
        if (overflow && overflow.classList.contains('tag-overflow')) {
            var expanding = overflow.classList.contains('is-hidden');
            overflow.classList.toggle('is-hidden', !expanding);
            btn.style.display = expanding ? 'none' : '';
        }
    });

    // ── Project page: desktop info-panel fold toggle ──
    var projectFoldToggle = document.getElementById('project-fold-toggle');
    if (projectFoldToggle) {
        // In compact mode (600-1024px) the sidebar overlays the viewer, so
        // start it collapsed — the viewer should be fully visible on first load.
        var compactMQ = window.matchMedia('(min-width: 37.5rem) and (max-width: 64rem)');
        if (compactMQ.matches || document.body.classList.contains('is-embedded')) {
            document.body.classList.add('is-info-collapsed');
        }
        // If the window resizes into or out of compact mode, update accordingly
        compactMQ.addEventListener('change', function (e) {
            if (e.matches) {
                document.body.classList.add('is-info-collapsed');
            } else {
                document.body.classList.remove('is-info-collapsed');
            }
        });

        projectFoldToggle.addEventListener('click', function () {
            document.body.classList.toggle('is-info-collapsed');
            // The viewer container size changed — let three.js / iframe react
            window.dispatchEvent(new Event('resize'));
        });
    }
});
