'use strict';
const app = new URLSearchParams(location.search).get('app');
const target = new URL('./', document.baseURI);
if (app !== null) target.searchParams.set('app', app); else target.hash = 'projects';
location.replace(SiteLanguage.url(target));
