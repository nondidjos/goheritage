// GoHéritage — global entry point
// handles mobile menu toggle, compare slider, procédé steps

document.addEventListener('DOMContentLoaded', function () {
    // mobile hamburger menu
    const toggle = document.getElementById('mobile-menu-toggle');
    const nav = document.getElementById('site-nav');

    if (toggle && nav) {
        toggle.addEventListener('click', function () {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!expanded));
            nav.classList.toggle('is-open');
            document.body.classList.toggle('nav-open');
        });
    }

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
            // crossfade images
            images.forEach(function (img) {
                img.classList.toggle('is-hidden', parseInt(img.dataset.step) !== idx);
            });

            // slide cards + update number colors
            steps.forEach(function (s, i) {
                var isActive = parseInt(s.dataset.step) === idx;
                var num = s.querySelector('span');
                if (num) {
                    num.className = num.className
                        .replace(/text-(ink|faint)/g, '')
                        .trim() + (isActive ? ' text-ink' : ' text-faint');
                }
                s.style.left = positions[idx][i];
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

    // ── Blog: client-side tag filtering ──
    var tagBtns = document.querySelectorAll('[data-filter-tag]');
    var blogArticles = document.querySelectorAll('[data-article-tags]');
    var clearBtn = document.getElementById('blog-clear-tags');
    var noResults = document.getElementById('blog-no-results');

    if (tagBtns.length && blogArticles.length) {
        var selectedBlogTags = new Set();

        tagBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tag = btn.dataset.filterTag;
                if (selectedBlogTags.has(tag)) {
                    selectedBlogTags.delete(tag);
                    btn.classList.remove('tag--active');
                } else {
                    selectedBlogTags.add(tag);
                    btn.classList.add('tag--active');
                }
                applyBlogFilters();
            });
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                selectedBlogTags.clear();
                tagBtns.forEach(function (b) { b.classList.remove('tag--active'); });
                applyBlogFilters();
            });
        }

        function applyBlogFilters() {
            var hasTags = selectedBlogTags.size > 0;
            var visibleCount = 0;

            blogArticles.forEach(function (article) {
                if (!hasTags) {
                    article.style.display = '';
                    visibleCount++;
                    return;
                }
                var tags = JSON.parse(article.dataset.articleTags || '[]');
                var matches = tags.some(function (t) { return selectedBlogTags.has(t.trim()); });
                article.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });

            // show/hide the "× Effacer tout" tag button
            if (clearBtn) {
                clearBtn.classList.toggle('hidden!', !hasTags);
            }

            // no-results message
            if (noResults) {
                noResults.classList.toggle('hidden', !hasTags || visibleCount > 0);
            }
        }
    }
});
