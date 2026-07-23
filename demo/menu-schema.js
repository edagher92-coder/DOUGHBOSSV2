/* Build menu JSON-LD from the same visible catalogue customers order from. */
(function () {
	'use strict';
	function text(node) { return node ? node.textContent.replace(/\s+/g, ' ').trim() : ''; }
	function menuItem(node) {
		var nameNode = node.querySelector('.mn-it-n');
		var cleanName = nameNode ? nameNode.cloneNode(true) : null;
		if (cleanName) {
			Array.prototype.forEach.call(cleanName.querySelectorAll('.mn-tag'), function (tag) { tag.remove(); });
		}
		var priceNode = node.querySelector('.mn-it-p');
		var price = priceNode ? (priceNode.getAttribute('data-price') || text(priceNode).replace(/[^0-9.]/g, '')) : '';
		return {
			'@type': 'MenuItem',
			'name': text(cleanName),
			'description': text(node.querySelector('.mn-it-d')),
			'offers': { '@type': 'Offer', 'price': Number(price || 0).toFixed(2), 'priceCurrency': 'AUD' }
		};
	}
	function build() {
		var sections = Array.prototype.map.call(document.querySelectorAll('#view-menu .mn-cat'), function (section) {
			return {
				'@type': 'MenuSection',
				'name': text(section.querySelector('.mn-cat-head h2')),
				'hasMenuItem': Array.prototype.map.call(section.querySelectorAll('.mn-list .mn-item'), menuItem)
			};
		});
		if (!sections.length) { return; }
		var schema = document.createElement('script');
		schema.id = 'doughboss-menu-schema';
		schema.type = 'application/ld+json';
		schema.textContent = JSON.stringify({ '@context': 'https://schema.org', '@type': 'Menu', 'name': 'Dough Boss Menu', 'inLanguage': 'en-AU', 'hasMenuSection': sections });
		document.head.appendChild(schema);
	}
	if ('loading' === document.readyState) { document.addEventListener('DOMContentLoaded', build); }
	else { build(); }
}());
