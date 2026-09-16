/**
 * @file cypress/tests/functional/OjsbrServices.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: who may reach the plugin's page, and what the public
 * endpoints answer to a request that carries no valid signature.
 *
 * Nothing is sent to the connector here — that would need the OJSBR service and
 * its private key. What these guard is the part that lives in OJS: the editor's
 * screen is behind the login, and heartbeat, callback and key refuse anything
 * that is not signed, whatever it claims to be.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run). The defaults match the data set of PKP's continuous
 * integration; the first test enables the plugin when it is off.
 */

describe('OJSBR Services plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';

	const rowName = 'ojsbrservicesplugin';

	// The language of the journal, taken from the URL the site answers with.
	let uiLocale = 'en';

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	// jQuery may not be on the page yet when this runs, so the check retries on the window
	// itself instead of on a property that would resolve as undefined.
	const waitJQuery = () => cy.window({timeout: 60000}).should((win) => {
		expect(win.jQuery && win.jQuery.active, 'pending jQuery requests').to.eq(0);
	});

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// The website settings page on its Plugins tab (a new query string forces a load).
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// ---- end of helpers ----

	// A request to one of the public operations, with whatever headers are given.
	// The language goes in the URL: a redirect would turn the POST into a GET and
	// the body would never reach the plugin.
	const callEndpoint = (op, headers) => cy.request({
		method: 'POST',
		url: pageUrl(uiLocale + '/ojsbr/' + op) + '?cb=' + Date.now(),
		body: {ts: Math.floor(Date.now() / 1000), nonce: 'cypress'},
		headers: Object.assign({'Content-Type': 'application/json'}, headers || {}),
		failOnStatusCode: false,
	});

	it('Enables the plugin', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(rowName);
	});

	it('Keeps the editor screen to signed-in editors', function() {
		cy.clearCookies();
		// OJS first redirects to the URL with the language; the page ends at the login form.
		cy.request({url: pageUrl('ojsbr'), failOnStatusCode: false}).then((response) => {
			const match = /\/index\.php\/[^/]+\/([^/]+)\/(?:ojsbr|login)/.exec(response.allRequestResponses
				? response.allRequestResponses[response.allRequestResponses.length - 1]['Request URL']
				: '');
			if (match) {
				uiLocale = match[1];
			}
			expect(response.body).to.match(/<form[^>]*id="login"/);
		});

		// Signed in, the same address answers with the plugin's screen. The page is
		// read as it comes from the server: other plugins of the installation load
		// their own scripts there, and a failure of theirs is not this spec's.
		login(adminUser, adminPassword);
		request({url: pageUrl('ojsbr') + '?reload=' + Date.now()}).then((response) => {
			expect(response.status).to.eq(200);
			expect(response.body).to.not.match(/<form[^>]*id="login"/);
			expect(response.body).to.contain('ojsbr-services-page');
		});
	});

	it('Refuses a request to the connector endpoints that is not signed', function() {
		// No signature at all.
		callEndpoint('heartbeat').its('status').should('eq', 401);
		// A signature that is not one, and a timestamp of the right shape.
		callEndpoint('callback', {
			'X-OJSBR-Timestamp': String(Math.floor(Date.now() / 1000)),
			'X-OJSBR-Signature': 'bm90LWEtc2lnbmF0dXJl',
		}).its('status').should('eq', 401);
		// The key rotation is no different: it is refused before anything is stored.
		callEndpoint('chave', {
			'X-OJSBR-Timestamp': '1',
			'X-OJSBR-Signature': 'bm90LWEtc2lnbmF0dXJl',
		}).its('status').should('eq', 401);
	});
});
