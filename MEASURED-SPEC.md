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
