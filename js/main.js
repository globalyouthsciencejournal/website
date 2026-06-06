;(function () {

    'use strict';

    var owlCarousel = function(){

        $('#slider1').owlCarousel({
            loop: false,
            margin: 10,
            dots: false,
            nav: true,
            navText: ["<i class='fa fa-angle-left'></i>", "<i class='fa fa-angle-right'></i>"],
            responsive: {
                0: {
                    items: 1
                },
                600: {
                    items: 3
                },
                1000: {
                    items: 4
                }
            }
        });

        $('#slider2').owlCarousel({
            loop: false,
            margin: 10,
            dots: false,
            nav: true,
            navText: ["<i class='fa fa-angle-left'></i>", "<i class='fa fa-angle-right'></i>"],
            responsive: {
                0: {
                    items: 1
                },
                600: {
                    items: 2
                },
                1000: {
                    items: 3
                }
            }
        });

        $('#slider3').owlCarousel({
            loop: false,
            margin: 10,
            dots: false,
            nav: true,
            navText: ["<i class='fa fa-angle-left'></i>", "<i class='fa fa-angle-right'></i>"],
            responsive: {
                0: {
                    items: 1
                },
                600: {
                    items: 2
                },
                1000: {
                    items: 3
                }
            }
        });

    };


    var videos = function() {


        $(document).ready(function () {
            $('#play-video').on('click', function (ev) {
                $(".fh5co_hide").fadeOut();
                $("#video")[0].src += "&autoplay=1";
                ev.preventDefault();

            });
        });


        $(document).ready(function () {
            $('#play-video_2').on('click', function (ev) {
                $(".fh5co_hide_2").fadeOut();
                $("#video_2")[0].src += "&autoplay=1";
                ev.preventDefault();

            });
        });

        $(document).ready(function () {
            $('#play-video_3').on('click', function (ev) {
                $(".fh5co_hide_3").fadeOut();
                $("#video_3")[0].src += "&autoplay=1";
                ev.preventDefault();

            });
        });


        $(document).ready(function () {
            $('#play-video_4').on('click', function (ev) {
                $(".fh5co_hide_4").fadeOut();
                $("#video_4")[0].src += "&autoplay=1";
                ev.preventDefault();

            });
        });
    };

    var googleTranslateFormStyling = function() {
        $(window).on('load', function () {
            $('.goog-te-combo').addClass('form-control');
        });
    };


    var contentWayPoint = function() {
        var i = 0;

        $('.animate-box').waypoint( function( direction ) {

            if( direction === 'down' && !$(this.element).hasClass('animated-fast') ) {

                i++;

                $(this.element).addClass('item-animate');
                setTimeout(function(){

                    $('body .animate-box.item-animate').each(function(k){
                        var el = $(this);
                        setTimeout( function () {
                            var effect = el.data('animate-effect');
                            if ( effect === 'fadeIn') {
                                el.addClass('fadeIn animated-fast');
                            } else if ( effect === 'fadeInLeft') {
                                el.addClass('fadeInLeft animated-fast');
                            } else if ( effect === 'fadeInRight') {
                                el.addClass('fadeInRight animated-fast');
                            } else {
                                el.addClass('fadeInUp animated-fast');
                            }

                            el.removeClass('item-animate');
                        },  k * 50, 'easeInOutExpo' );
                    });

                }, 100);

            }

        } , { offset: '85%' } );
        // }, { offset: '90%'} );
    };


	var goToTop = function() {

		$('.js-gotop').on('click', function(event){
			
			event.preventDefault();

			$('html, body').animate({
				scrollTop: $('html').offset().top
			}, 500, 'swing');
			
			return false;
		});

		$(window).scroll(function(){

			var $win = $(window);
			if ($win.scrollTop() > 200) {
				$('.js-top').addClass('active');
			} else {
				$('.js-top').removeClass('active');
			}

		});
	
	};

    var setActiveNavbarLink = function() {
        var pageKeyFromPathname = function(pathname) {
            if (!pathname) return 'index';
            var cleaned = String(pathname);
            // Remove any trailing slash (except when pathname is just "/")
            if (cleaned.length > 1 && cleaned.endsWith('/')) cleaned = cleaned.slice(0, -1);
            var parts = cleaned.split('/').filter(function(p){ return p; });
            var last = parts.length ? parts[parts.length - 1] : '';
            if (!last) return 'index';

            var lower = String(last).toLowerCase();
            // Treat "/", "/index.html", and "/index.php" as the same page key
            if (lower === 'index.html' || lower === 'index.php') return 'index';

            // Normalize extensions so links match across .html/.php/extensionless routes
            last = String(last)
                .replace(/\.html$/i, '')
                .replace(/\.php$/i, '');

            return last || 'index';
        };

        var currentKey;
        try {
            currentKey = pageKeyFromPathname(window.location.pathname);
        } catch (e) {
            currentKey = 'index';
        }

        // Clear any hardcoded active states first
        $('.navbar-nav .nav-item').removeClass('active');
        $('.navbar-nav .nav-link').removeClass('active');
        $('.navbar-nav .dropdown-item').removeClass('active');

        var matched = false;
        $('.navbar-nav a').each(function(){
            if (matched) return;
            var $link = $(this);
            var href = $link.attr('href');
            if (!href || href === '#' || href.toLowerCase().startsWith('javascript:')) return;

            var linkKey;
            try {
                var url = new URL(href, window.location.href);
                linkKey = pageKeyFromPathname(url.pathname);
            } catch (e) {
                return;
            }

            // Normalize home links
            if (linkKey === '' || linkKey === '/') linkKey = 'index';

            if (String(linkKey).toLowerCase() === String(currentKey).toLowerCase()) {
                matched = true;
                $link.addClass('active');
                if ($link.hasClass('dropdown-item')) {
                    var $dropdown = $link.closest('.nav-item.dropdown');
                    $dropdown.addClass('active');
                    $dropdown.find('> a.nav-link').addClass('active');
                } else {
                    $link.closest('.nav-item').addClass('active');
                }
            }
        });
    };

	
	$(function(){
		owlCarousel();
		videos();
        contentWayPoint();
		goToTop();
		googleTranslateFormStyling();
        setActiveNavbarLink();
	});

}());
function googleTranslateElementInit() {
    new google.translate.TranslateElement({pageLanguage: 'en'}, 'google_translate_element');
}

// Cookie Consent Popup
(function() {
    if (!localStorage.getItem('cookieAccepted')) {
        var cookieHtml = `
            <div id="cookie-consent-popup" style="position: fixed; bottom: 110px; right: 20px; width: 350px; background: #fff; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.2); z-index: 9999; display: flex; flex-direction: column; align-items: flex-start; text-align: left; font-family: 'Poppins', sans-serif; border: 1px solid #ddd; border-radius: 0;">
                <p style="margin-top: 0; margin-bottom: 15px; font-size: 13px; color: #333; line-height: 1.5;">
                    We use cookies and similar technologies to operate our website, analyze usage, improve performance, and enhance your experience. Some cookies are essential for the website to function, while others help us understand how visitors interact with our content.<br><br>
                    By selecting "Accept All," you consent to the use of non-essential cookies. You can manage your preferences at any time.
                </p>
                <div style="display: flex; gap: 10px; width: 100%; justify-content: space-between;">
                    <button id="cookie-manage-prefs" style="flex: 1; background: #f8f9fa; color: #333; border: 1px solid #ccc; padding: 10px 10px; border-radius: 0; cursor: pointer; font-weight: bold; font-size: 12px;">Manage Preferences</button>
                    <button id="cookie-accept-all" style="flex: 1; background: #ffc107; color: #000; border: none; padding: 10px 10px; border-radius: 0; cursor: pointer; font-weight: bold; font-size: 12px;">Accept All</button>
                </div>
            </div>
            
            <div id="cookie-manage-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; justify-content: center; align-items: center;">
                <div style="background: #fff; padding: 30px; border-radius: 0; max-width: 500px; width: 90%; text-align: left; font-family: 'Poppins', sans-serif; border: 1px solid #ddd;">
                    <h3 style="margin-top: 0; color: #333; font-size: 20px;">Manage Cookie Preferences</h3>
                    <div style="margin-bottom: 20px;">
                        <label style="display: flex; align-items: center; justify-content: space-between; font-weight: bold; color: #333; font-size: 16px;">
                            Essential Cookies
                            <input type="checkbox" style="accent-color: #007bff;" checked disabled>
                        </label>
                        <p style="font-size: 13px; color: #666; margin-top: 5px;">Required for the website to function properly.</p>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label style="display: flex; align-items: center; justify-content: space-between; font-weight: bold; color: #333; font-size: 16px;">
                            Non-Essential Cookies
                            <input type="checkbox" id="non-essential-toggle" style="accent-color: #007bff;" checked>
                        </label>
                        <p style="font-size: 13px; color: #666; margin-top: 5px;">Used for analytics and personalized experience.</p>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 10px;">
                        <button id="cookie-modal-close" style="background: #ccc; color: #333; border: none; padding: 8px 15px; border-radius: 0; cursor: pointer; font-weight: bold;">Cancel</button>
                        <button id="cookie-modal-save" style="background: #ffc107; color: #000; border: none; padding: 8px 15px; border-radius: 0; cursor: pointer; font-weight: bold;">Save Preferences</button>
                    </div>
                </div>
            </div>
        `;
        // Use a timeout to ensure body is fully parsed if needed, or just append it.
        var injectPopup = function() {
            if (document.body) {
                document.body.insertAdjacentHTML('beforeend', cookieHtml);

                document.getElementById('cookie-accept-all').addEventListener('click', function() {
                    localStorage.setItem('cookieAccepted', 'true');
                    document.getElementById('cookie-consent-popup').style.display = 'none';
                });

                document.getElementById('cookie-manage-prefs').addEventListener('click', function() {
                    document.getElementById('cookie-manage-modal').style.display = 'flex';
                });

                document.getElementById('cookie-modal-close').addEventListener('click', function() {
                    document.getElementById('cookie-manage-modal').style.display = 'none';
                });

                document.getElementById('cookie-modal-save').addEventListener('click', function() {
                    localStorage.setItem('cookieAccepted', 'true');
                    document.getElementById('cookie-manage-modal').style.display = 'none';
                    document.getElementById('cookie-consent-popup').style.display = 'none';
                });
            } else {
                setTimeout(injectPopup, 100);
            }
        };
        injectPopup();
    }
})();