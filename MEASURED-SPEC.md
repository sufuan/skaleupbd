# Measured Spec — EcomDrive Portal (live measurements, not impressions)

Method: open the live page, measure rendered geometry + sampled colours, write the numbers here, build only to these numbers, then screenshot-compare at the same viewport.
Source: portal.ecomdrivebd.com demo workspace, measured 2026-11-10 ~20:59 +06 at 1920×999 viewport (sidebar fixed-width values hold at any width; fluid card widths scale).

## Page 1 — Shell + Dashboard (My Setup view)

### Shell
- Sidebar total width: 268px (panel 260px + 1px divider #E5E7EB); content starts x268
- Sidebar background: #FFFFFF
- Sidebar item: height 36px; box inset 8px each side; icon 12px from item left; text starts 40px from item left; font 14px; weight inactive 500 / active 600
- Sidebar item colours: inactive text #111827; active text #411550 on background #F4EDF7
- Group label: 11px, weight 600, colour #94A3B8, uppercase, letter-spacing ~0.05em
- Top bar: height 56px; background #F8F7FB; bottom border 1px #EEF2F7
- Content background: #F8F7FB
- Content padding: top 32px, left 52px, right 52px

### View tabs row
- Tab font 14px; active weight 600 colour #111827; inactive colour #64748B
- Active underline: 2px, colour #42174F
- Gap between tabs: 24–28px
- Clock pill ~32px high; "DHAKA TIME" label 9–10px #64748B; time ~13px #111827
- Date/nav icon buttons ~32×32px; Today date button ~110px wide, 30–32px high

### KPI cards (×4)
- Height 112px; background #FFFFFF; border 1px #E5E7EB; radius ~12px
- Padding ~16px (left 16, right ~20)
- Label: 13px, weight 500, colour #64748B, normal case, no letter-spacing
- Value: 30px, weight 700, colour #111827
- Sub-text: 12–13px; green % colour #1CA477; muted part #64748B
- Gap between KPI cards: 18px
- Card-header filter button ~24×24px; "Today" pill ~23px high, ~70px wide, font 11–12px

### Content cards
- Background #FFFFFF; border 1px #E5E7EB; radius ~12px; padding 24px
- Card title: 16px, weight 600, colour #111827
- Gaps: horizontal 16px; KPI row → content row 18px
- Two-column split: 50/50 with 16px gap

### Global
- Font: Inter-style sans (est.)
- Primary dark purple: #411550 (tab underline family #42174F; logo shading to #4B2159)
- Chart accent (donut): #4F46E5
- Body text: #111827; muted text: #64748B
- Borders: #E5E7EB (main), #EEF2F7 (rim/top-bar)
- Success green: #1CA477 / #009966

## Page 2 — Web Order List (measured 2026-11-10 ~22:21 +06, 1920px)
- No heading; EVERYTHING (status tabs + toolbar + table + pagination) inside ONE white card: border 1px #E5E7EB, radius ~12px, card top ~9px below topbar; tabs/toolbar padding ~8px; table content inset ~22px.
- Status row = TABS, not filled pills: label 14px (active 600 / inactive 500) + count badge ~24×18px pill. Active: text #42174F, 2px underline #42174F spanning tab incl. badge, NO fill; badge bg #E3DCE5, text #411550. Inactive: text #64748B; badge bg #F1F5F9, text #64748B. Gap 13–16px; row ~40px.
- Toolbar controls ~30px tall, radius ~8px, border 1px #E5E7EB: search input 340px wide, bg #F8F7FB, placeholder #64748B 14px; Filter btn bg #F8F7FB text #111827; New btn bg #FFFBEE; Bulk Actions bg #F8F7FB; sliders + gear icon buttons bg #F8F7FB.
- Call-status button: one button holding 5 chips ~26px, font 12–13px: Not Called bg #FEF4F4 text #B91C1C; Tried 1× bg #FFFBEE; Tried 2× bg #FFFBEB; Tried 3+ bg #FFF8F0; Talked bg #EFFDF6 text #16A34A.
- Table header: bg #E8E3EA, height ~42px, font 13–14px weight 600, colour #411550; sort controls on Created At + Success Rate.
- Body: font 12–13px, colour #111827, muted #64748B; standard row ~83px tall; cell padding ~16px; divider 1px #E5E7EB; hover bg #F7F2F8.
- Success gauge: ring 28px + 3 lines of 12px text right ("Success: 11%" red #EF4444 low / green #16A34A high, "Order: 1/9", "Rating: 36"); no history = "0".
- Actions "Open": bordered pill button, bg #FFFFFF, border #E5E7EB, text #42174F, ~26px tall, ~85px wide, external-link icon.
- Pagination inside card: "Page 1" left, 13–14px #64748B; "Rows per page" + select 26px tall ~55px wide bg #F8F7FB border #E8E3EE value 20; right: "Page 1" bold #111827, "‹ Previous" #9CA3AF disabled, "Next ›" #111827.
