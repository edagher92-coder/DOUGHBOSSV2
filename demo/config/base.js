(function (root) {
	'use strict';

	root.DB_DEMO_BASE_CONFIG = {
		schemaVersion: 1,
		profileId: 'unconfigured',
		brand: {
			name: 'Ordering Platform',
			orderReferencePrefix: 'ORD',
			theme: { primary: '#111111', accent: '#b5571f', background: '#f6f1e9' },
			contact: { email: '', phone: '' }
		},
		region: {
			language: 'en-AU',
			fallbackLanguage: 'en-AU',
			locale: 'en-AU',
			currency: 'AUD',
			timezone: 'Australia/Sydney',
			direction: 'ltr'
		},
		defaultLocationId: '',
		locations: [],
		workflows: {
			pickup: {
				lanes: [
					{ id: 'new', className: 'c-new', labelKey: 'lane.new', statuses: ['new'] },
					{ id: 'kitchen', className: 'c-prep', labelKey: 'lane.kitchen', statuses: ['confirmed', 'preparing', 'baking'] },
					{ id: 'ready', className: 'c-ready', labelKey: 'lane.pickupReady', statuses: ['ready'] }
				],
				states: {
					new: { labelKey: 'status.new', action: { to: 'confirmed', labelKey: 'action.accept', requiresEta: true } },
					confirmed: { labelKey: 'status.accepted', action: { to: 'preparing', labelKey: 'action.startPrep' } },
					preparing: { labelKey: 'status.preparing', action: { to: 'baking', labelKey: 'action.startOven' } },
					baking: { labelKey: 'status.baking', action: { to: 'ready', labelKey: 'action.markPickupReady' } },
					ready: { labelKey: 'status.pickupReady', action: { to: 'completed', labelKey: 'action.collected' } },
					completed: { labelKey: 'status.collected', terminal: true },
					cancelled: { labelKey: 'status.cancelled', terminal: true }
				}
			},
			delivery: {
				lanes: [
					{ id: 'new', className: 'c-new', labelKey: 'lane.new', statuses: ['new'] },
					{ id: 'kitchen', className: 'c-prep', labelKey: 'lane.kitchen', statuses: ['confirmed', 'preparing', 'baking'] },
					{ id: 'dispatch', className: 'c-ready', labelKey: 'lane.dispatch', statuses: ['ready', 'out_for_delivery'] }
				],
				states: {
					new: { labelKey: 'status.new', action: { to: 'confirmed', labelKey: 'action.accept', requiresEta: true } },
					confirmed: { labelKey: 'status.accepted', action: { to: 'preparing', labelKey: 'action.startPrep' } },
					preparing: { labelKey: 'status.preparing', action: { to: 'baking', labelKey: 'action.startOven' } },
					baking: { labelKey: 'status.baking', action: { to: 'ready', labelKey: 'action.markDispatchReady' } },
					ready: { labelKey: 'status.dispatchReady', action: { to: 'out_for_delivery', labelKey: 'action.dispatch' } },
					out_for_delivery: { labelKey: 'status.outForDelivery', action: { to: 'completed', labelKey: 'action.delivered' } },
					completed: { labelKey: 'status.delivered', terminal: true },
					cancelled: { labelKey: 'status.cancelled', terminal: true }
				}
			}
		},
		payments: {
			enabled: false,
			allowedProviders: ['stripe', 'tyro'],
			selectedProvider: null,
			allowPayOnPickup: true
		},
		promotions: { studentVoucher: { enabled: false, amount: 0 } },
		demo: {
			noExternalWrites: true,
			noRealPayment: true,
			fixtureProfile: 'default'
		}
	};
}(typeof globalThis !== 'undefined' ? globalThis : this));
