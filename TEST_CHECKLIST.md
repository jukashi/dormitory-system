# Final test checklist

Complete these checks using a test account and sample records before entering real dormitory data.

- [ ] Log in with the admin account; log out; confirm that protected URLs redirect to login.
- [ ] Create a dormitory and room; edit them; verify a room cannot be deleted while linked to a tenant or reduced below its active occupancy.
- [ ] Add a tenant; upload a JPG/PNG/WebP image under 2 MB; verify profile information and photo display.
- [ ] As an administrator, add, rename, deactivate, and reactivate a tenant item under Dormitories & Rooms; confirm inactive items cannot be newly issued.
- [ ] Add a tenant with move-in item checkboxes selected; confirm each item appears on the tenant profile with the correct issue date and issuing user.
- [ ] Issue an item with a reference number, reject a duplicate active assignment, mark it returned, and issue the same item again; confirm the complete history remains visible.
- [ ] Move out a tenant who still holds an item; confirm the warning appears and the item remains outstanding until explicitly returned.
- [ ] In Reports, filter tenant items by item, assignment status, tenant status, and dormitory; export the filtered CSV and confirm its rows match the screen.
- [ ] Attempt to assign another active tenant to the same room/bed; confirm the system rejects it.
- [ ] Fill a room to capacity; confirm the next active tenant cannot be assigned there.
- [ ] Move out a tenant; confirm invalid dates are rejected, the occupied-bed count drops, and the bed can be reused.
- [ ] Record a payment; try a duplicate payment for the same month; confirm it is blocked unless an administrator explicitly permits it.
- [ ] Log a visitor and check them out; confirm a check-out before check-in is rejected and the date filter finds the record.
- [ ] Add and update a maintenance request; test every status and staff assignment.
- [ ] Add a tenant calendar event and comment; confirm it appears in the tenant's selected shift calendar.
- [ ] Import a small shift CSV with `DATE,WORK`; re-import it and confirm existing shift dates are updated without duplicates.
- [ ] Export every report CSV and open it in Excel or LibreOffice.
- [ ] Create a database and photo backup; restore it in a test environment before relying on the process.
- [ ] At phone width, confirm every navigation destination remains reachable and keyboard focus remains visible.
