{**
 * plugins/generic/plagiarismCheck/templates/styles.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * Shared styles for the report and results pages
 *}
<style>
	:root { --pc-bg: #f6f7f9; --pc-surface: #fff; --pc-text: #1d2330; --pc-muted: #5d6573; --pc-border: #dde1e7; --pc-accent: #006798; --pc-ok: #00733c; --pc-mid: #b35c00; --pc-high: #c4001a; }
	* { box-sizing: border-box; }
	body { margin: 0; background: var(--pc-bg); color: var(--pc-text); font: 15px/1.55 -apple-system, "Segoe UI", Roboto, "Noto Sans", Arial, sans-serif; }
	a { color: var(--pc-accent); }
	.pc-wrap { max-width: 1040px; margin: 0 auto; padding: 0 16px; }
	.pc-header { background: var(--pc-surface); border-bottom: 1px solid var(--pc-border); padding: 20px 0 16px; margin-bottom: 24px; }
	.pc-header h1 { margin: 2px 0 6px; font-size: 22px; line-height: 1.3; overflow-wrap: anywhere; }
	.pc-eyebrow { margin: 0; color: var(--pc-muted); font-size: 13px; text-transform: uppercase; letter-spacing: .04em; }
	.pc-meta { margin: 0; color: var(--pc-muted); font-size: 14px; }
	h2 { font-size: 17px; margin: 28px 0 10px; }
	.pc-summary { display: flex; flex-wrap: wrap; gap: 16px; align-items: stretch; }
	.pc-score { flex: none; background: var(--pc-surface); border: 1px solid var(--pc-border); border-left: 6px solid var(--pc-ok); border-radius: 8px; padding: 12px 20px; min-width: 160px; }
	.pc-score--medium { border-left-color: var(--pc-mid); }
	.pc-score--high { border-left-color: var(--pc-high); }
	.pc-score__value { display: block; font-size: 34px; font-weight: 700; font-variant-numeric: tabular-nums; }
	.pc-score__label { color: var(--pc-muted); font-size: 13px; }
	.pc-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; flex: 1 1 320px; min-width: 0; margin: 0; }
	.pc-facts div { background: var(--pc-surface); border: 1px solid var(--pc-border); border-radius: 8px; padding: 10px 14px; }
	.pc-facts dt { color: var(--pc-muted); font-size: 13px; }
	.pc-facts dd { margin: 0; font-size: 20px; font-weight: 600; font-variant-numeric: tabular-nums; }
	.pc-note { color: var(--pc-muted); font-size: 13px; margin: 14px 0 0; }
	.pc-sources { list-style: none; margin: 0; padding: 0; background: var(--pc-surface); border: 1px solid var(--pc-border); border-radius: 8px; }
	.pc-source { display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; padding: 10px 14px; border-top: 1px solid var(--pc-border); }
	.pc-source:first-child { border-top: 0; }
	.pc-source__title { flex: 1 1 240px; min-width: 0; overflow-wrap: anywhere; }
	.pc-source__title small { display: block; color: var(--pc-muted); }
	.pc-source__score { margin-left: auto; font-weight: 600; white-space: nowrap; font-variant-numeric: tabular-nums; }
	.pc-source__score small { font-weight: 400; color: var(--pc-muted); }
	.pc-swatch { width: 14px; height: 14px; border-radius: 3px; flex: none; }
	.pc-text { min-width: 0; background: var(--pc-surface); border: 1px solid var(--pc-border); border-radius: 8px; padding: 20px; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 14px; line-height: 1.7; margin-bottom: 40px; }
	.pc-text mark { color: inherit; border-radius: 2px; }
	.pc-excluded { color: #9aa1ab; }
	.pc-c0 { background: #ffd6d6; } .pc-c1 { background: #cfe3ff; } .pc-c2 { background: #d4f3d0; } .pc-c3 { background: #ffe6bf; } .pc-c4 { background: #e7d8ff; }
	.pc-c5 { background: #c9f1ef; } .pc-c6 { background: #ffd9ef; } .pc-c7 { background: #eaf2b8; } .pc-c8 { background: #dfe3e8; } .pc-c9 { background: #f8dcc6; }
	.pc-empty { background: var(--pc-surface); border: 1px solid var(--pc-border); border-radius: 8px; padding: 20px; }
	.pc-table-wrap { overflow-x: auto; background: var(--pc-surface); border: 1px solid var(--pc-border); border-radius: 8px; margin-bottom: 40px; }
	.pc-table { width: 100%; border-collapse: collapse; font-size: 14px; }
	.pc-table th, .pc-table td { text-align: left; padding: 9px 12px; border-top: 1px solid var(--pc-border); vertical-align: top; }
	.pc-table th { border-top: 0; color: var(--pc-muted); font-weight: 600; font-size: 13px; white-space: nowrap; }
	.pc-table small { color: var(--pc-muted); }
	.pc-num { text-align: right !important; white-space: nowrap; font-variant-numeric: tabular-nums; }
	.pc-nowrap { white-space: nowrap; }
	.pc-table td:nth-child(2) { min-width: 240px; overflow-wrap: anywhere; }
	.pc-badge { font-weight: 700; color: var(--pc-ok); text-decoration: none; }
	.pc-badge--medium { color: var(--pc-mid); }
	.pc-badge--high { color: var(--pc-high); }
	.pc-failed { color: var(--pc-high); cursor: help; }
	@media (max-width: 600px) {
		.pc-header h1 { font-size: 19px; }
		.pc-text { padding: 14px; }
	}
</style>
