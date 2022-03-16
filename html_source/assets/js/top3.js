(function(){

	var backTop = document.getElementsByClassName('footer-back-to-top')[0],
		// browser window scroll (in pixels) after which the "back to top" link is shown
		offset = 300,
		//browser window scroll (in pixels) after which the "back to top" link opacity is reduced
		offsetOpacity = 1200,
		scrollDuration = 700,
		scrolling = false,
		timeout = 0;

	if( backTop ) {

		window.addEventListener("scroll", checkScroll);

		history.scrollRestoration = "manual";

		backTop.addEventListener('click', function(event) {
			event.preventDefault();
			if (timeout) clearTimeout(timeout);

			timeout = setTimeout(function() {
				window.scrollTo({ left: 0, top: 0, behavior: "smooth"});
			}, 200)
		});
	}

	function checkScroll(event) {
		if (timeout) clearTimeout(timeout);
		(!window.requestAnimationFrame) ? timeout = setTimeout(checkBackToTop, 250) : window.requestAnimationFrame(checkBackToTop);
	}

	function getCurrentScrollY() {
		if (isNaN(window.scrollY)) {
			return window.pageYOffset;
		} else {
			return window.scrollY;
		}
	}

	function checkBackToTop() {
		var windowTop = getCurrentScrollY();
		( windowTop > offset ) ? addClass(backTop, 'cd-top--show') : removeClass(backTop, 'cd-top--show', 'cd-top--fade-out');
		( windowTop > offsetOpacity ) && addClass(backTop, 'cd-top--fade-out');
		scrolling = false;
	}


	//class manipulations - needed if classList is not supported
	function hasClass(el, className) {
		if (el.classList) return el.classList.contains(className);
		else return !!el.className.match(new RegExp('(\\s|^)' + className + '(\\s|$)'));
	}
	function addClass(el, className) {
		var classList = className.split(' ');
		if (el.classList) el.classList.add(classList[0]);
		else if (!hasClass(el, classList[0])) el.className += " " + classList[0];
		if (classList.length > 1) addClass(el, classList.slice(1).join(' '));
	}
	function removeClass(el, className) {
		var classList = className.split(' ');
		if (el.classList) el.classList.remove(classList[0]);
		else if(hasClass(el, classList[0])) {
			var reg = new RegExp('(\\s|^)' + classList[0] + '(\\s|$)');
			el.className=el.className.replace(reg, ' ');
		}
		if (classList.length > 1) removeClass(el, classList.slice(1).join(' '));
	}

})();