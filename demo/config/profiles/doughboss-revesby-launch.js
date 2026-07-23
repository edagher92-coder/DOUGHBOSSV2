(function (root) {
	'use strict';

	root.DB_DEMO_PROFILE = {
		profileId: 'doughboss-revesby-launch',
		brand: {
			name: 'Dough Boss',
			orderReferencePrefix: 'DB',
			theme: { primary: '#111111', accent: '#b5571f', background: '#f6f1e9' },
			contact: { email: 'hello@doughboss.com.au', phone: '+61297742286' }
		},
		region: {
			language: 'en-AU',
			fallbackLanguage: 'en-AU',
			locale: 'en-AU',
			currency: 'AUD',
			timezone: 'Australia/Sydney',
			direction: 'ltr'
		},
		defaultLocationId: 'revesby',
		locations: [
			{
				id: 'revesby',
				name: 'Revesby',
				address: '12/25 Selems Parade, Revesby NSW 2212',
				phone: '+61297742286',
				displayPhone: '(02) 9774 2286',
				hours: 'Daily 6:30am–2:30pm',
				/* The checkout reads this field directly. Keep the public display copy and
				   the operational window together so the customer never sees two sets
				   of Revesby hours. Minutes are measured from midnight, local time. */
				orderingHours: {
					display: 'Daily 6:30am–2:30pm',
					days: { 0: [390, 870], 1: [390, 870], 2: [390, 870], 3: [390, 870], 4: [390, 870], 5: [390, 870], 6: [390, 870] }
				},
				active: true,
				fulfilment: {
					pickup: { enabled: true, labelKey: 'fulfilment.pickup' },
					delivery: { enabled: false, labelKey: 'fulfilment.delivery', addressRequired: true, fee: 0 }
				},
				thirdPartyDelivery: [
					{ name: 'Uber Eats', url: 'https://www.ubereats.com/au/store/dough-boss-revesby/1pTldQavTxSJtAUj9nUn7g' }
				]
			}
		],
		payments: {
			enabled: false,
			allowedProviders: ['stripe', 'tyro'],
			selectedProvider: null,
			allowPayOnPickup: true
		},
		promotions: { studentVoucher: { enabled: true, amount: 5 } },
		demo: {
			noExternalWrites: true,
			noRealPayment: true,
			fixtureProfile: 'revesby-launch'
		}
	};
}(typeof globalThis !== 'undefined' ? globalThis : this));
