# Yak desk buddy: first test print

A 1.5x walker with a cap (fringe, crown, horns) that slides straight up on two columns to show two round screen eyes. Built for a Bambu H2C, 0.4 mm nozzle, PLA, AMS. This is a first test print, so expect to adjust fits.

Source: docs/brand/source/desk_buddy.py. Regenerate with `blender -b -P docs/brand/source/desk_buddy.py -- docs/brand/print/desk-buddy` then `python3 docs/brand/source/desk_buddy_sheet.py docs/brand/print/desk-buddy`. Interference checks run in closed, peek and open poses and all report 0 mm3.

## Key numbers

- Outer size, closed: 70.2 x 52.4 x 106.5 mm (width includes the horns; head and body are 54 x 45; the extra depth is the USB plug saddle on the back).
- Outer size, open: 70.2 x 52.4 x 133.6 mm.
- Cap travel: closed 0, peek 10.5 mm, open 27.1 mm.
- Hard stop at 33.5 mm, so 6.4 mm of margin over the open pose.
- Drive: 24 tooth, module 1 pinion straight on the MG90S spline, pitch radius 12 mm. 27.1 mm is 129 degrees of servo rotation, 33.5 mm is 160 degrees. Limit the servo to 0 to 150 degrees in firmware.
- Servo: MG90S (metal gear), 22.8 x 12.2 x 28.5 mm with spline, 20 tooth spline (from retailer listings). Ear spacing (27.8 mm holes), ear plate position, spline offset (5.75 mm from the end) and spline length (5 mm) are carried over from the SG90 and are assumptions. Check against your servo.
- Fits: 0.25 mm per side on sliding fits, 0.15 mm per side on press fits, 0.15 mm gear backlash.

## Printed parts

Supports: none are required by design. The overhang check (downward faces steeper than 45 degrees) found only small bridges, listed in the last column. Last run: cap 11 mm2, horns 6 mm2 each, pinion 12 mm2, head 764 mm2 and tub 625 mm2 (ribs, tabs, the window arches, the saddle and the tie holes, all short bridges), everything else 0.

| Part | Colour | Orientation | Notes |
|---|---|---|---|
| tub | slate | legs on the bed, as is | Body from the legs to the seam at z 44. Holds the ESP32, capacitor, connector pocket and cable lane. Two saddle blocks and two tie holes at the back opening hold the USB-C plug with a cable tie (about 2.5 mm wide). Bridges: underside between the legs and arms (as the original walker), USB-C slot arch, screw holes. No supports needed in practice. |
| head | slate | upside down, roof on the bed | Eye windows, module cradles, servo pocket, guide tubes, screw tabs. Roof edge is cut at 45 degrees so it prints cleanly. The two 17.6 mm window arches are the one real bridge, tree supports there are optional if they sag. |
| cap | cream | upside down, crown on the bed | The sleeve with fringe locks, column bosses and horn pin pockets. Skirt wall is 1.6 mm, crown 1.7 mm. No supports. |
| horn_rust | rust | upright, flat base down | Leans at 45 degrees, no supports. Two 1.75 mm filament pins. |
| horn_sage | sage | upright, flat base down | As above. |
| muzzle | peach | back face down | Glued to the tub front. No supports. |
| rack | any, stiff | lying on its side, teeth in the print plane | One of the two columns. 65 mm. Print at 0.12 mm layers, 5 walls. No supports. |
| guide_column | any | lying on its side | The second column, with a cross hole for the stop pin. 64 mm. |
| pinion | any | flat, back face up is not needed, prints as is | 24 teeth, module 1, 6.6 mm wide. Back face has a recess for the stock single-arm horn (assumed hub 7.0 mm, arm 4.5 mm wide, trimmed to 9 mm from the centre, plate 2 mm, 0.15 mm clearance). The horn's centre screw passes through a 3.0 mm hole. The small arm slot is a short bridge (about 12 mm2). 0.12 mm layers. |
| esp_strap | any | flat | Holds the ESP32 board down with one M2.5 screw into a boss, so the board is screwed down. |

STL files are in stl/, already in print orientation. Colour assignment is by file (the cap is one colour, horns are separate parts).

Columns are separate parts so their layers run along their length, which is much stronger than printing them upright on the cap.

## Bought parts (rough prices, USD)

- Seeed XIAO ESP32-S3, 1 pc, about 8
- Waveshare 0.71 inch round LCD module (GC9D01, 20.12 x 22.3 mm board, 18 mm display), 2 pcs, about 10 each
- MG90S micro servo with metal gears, 1 pc, about 5 (stock horns are included: you use the single-arm horn)
- Electrolytic capacitor, 470 to 1000 uF, 6.3 V or 10 V, 8 mm x 12 mm, 1 pc, about 1
- Inline connector for the servo lead: JST-PH or 3-pin Dupont housing pair, about 3
- Silicone wire 28 to 30 AWG, a few colours, about 8
- USB-C cable, about 5
- 1.75 mm filament offcuts for horn pins (free)
- Cyanoacrylate glue or two-part epoxy, about 5
- Screws (about 6 for an assortment):
  - 2 x M2.5 x 10 self-tapping, head to tub at the rear (2.2 mm pilot in the head tab, 2.7 mm clearance in the tub)
  - 2 x M2.5 x 10 self-tapping, head to tub at the front, just below the lock tips (reachable with the cap raised or removed; same hole sizes)
  - Cable tie, 2.5 mm wide, 1 pc, for the USB plug saddle
  - 2 x M2.5 x 14 self-tapping, head to tub at the sides through the arms (2.2 mm pilot)
  - 1 x M2.5 x 6 self-tapping, ESP32 strap (2.2 mm pilot)
  - 2 x M2 x 6, servo ears (2.0 mm pilot, usually supplied with the servo)
  - 1 x M2 x 4, pinion centre screw (supplied with the servo horn)
  - 1 x M2 x 10 screw as the cross stop pin on the guide column (2.0 mm hole in the column)

## Wiring

All point to point, no custom PCB, no driver boards. SG90 runs from the XIAO 5V pin and one GPIO. Both screens share the SPI bus with separate chip selects.

| Signal | XIAO ESP32-S3 pin | Goes to |
|---|---|---|
| 5V | 5V pad | servo red, capacitor plus |
| GND | GND | servo brown, capacitor minus, both screen GND |
| Servo signal | D1 (GPIO2) | servo orange |
| 3V3 | 3V3 | both screen VCC |
| SCK | D8 (GPIO7) | both screen CLK |
| MOSI | D10 (GPIO9) | both screen DIN |
| DC | D0 (GPIO1) | both screen DC |
| RST | D2 (GPIO3) | both screen RST |
| BL | D3 (GPIO4) | both screen BL |
| CS1 | D4 (GPIO5) | left screen CS |
| CS2 | D5 (GPIO6) | right screen CS |

D9 (MISO, GPIO8) is unused. Check the screen BL input: if it draws more than a GPIO can give, tie BL to 3V3 and drop the BL wire.

Put the capacitor across the servo supply, as close to the servo wires as you can. A USB port limits the servo to its usual load, so keep the cap speed gentle.

Room inside the body:

- Capacitor: a ring on the tub floor holds an 8 mm x 12 mm can, rear right.
- Connector: a pocket on the tub floor, front right, holds the inline connector on the servo lead so the head can come off.
- Screen wires: leave the bottom of each module, run down the inside of the front wall, then along the lane (two low ribs on the tub floor) to the ESP32.
- Servo wire: from the servo at the back of the head, down through the open seam to the connector pocket. Leave about 60 mm of slack so the head can be set aside.

## Assembly

1. Print all parts. Clean the gear and rack, test the pinion on a loose rack.
2. Press the screen modules into the head cradles from the bottom, glass against the front wall, until they stop under the top blocks. A drop of glue on the lips is fine.
3. Fit the servo into its pocket from the bottom, spline pointing forward, ears against the two bosses, and screw both ears (M2).
4. Press the stock single-arm horn onto the spline (trim the arm to 9 mm), then seat the pinion over it with a drop of glue and fix it with the horn's centre screw. (See risk 2.)
5. Drop the rack and the guide column up through the head's guide tubes from below. Fit the cross stop pin to the guide column first.
6. Glue the column tops into the cap bosses (cap upside down is easiest). Check that the cap rests flat on the head roof and the pinion turns freely.
7. Glue the two horns on with a short filament pin in each hole.
8. Glue the muzzle to the tub front.
9. In the tub: seat the ESP32 on its pad between the rails with the USB-C port in the back slot. Fit the strap with one screw. Place the capacitor in its ring and the connector in its pocket.
10. Solder wires per the table, plug the servo connector, and test with the head off: servo through its range before it is closed up.
11. Set the head on the tub (the ring locates it) and fix it with six M2.5 screws (two rear, two front, two through the arms).
12. Run the servo to 0 degrees (cap down), then step up toward the open pose and stop well before the 33.5 mm hard stop.

## Known risks

1. MG90S torque is about 2 kg.cm, fine for a cap of about 40 g plus friction. Print the cap with few walls to stay light.
2. The stock horn size is assumed. Measure your horn and change the recess in build_drive() if it differs. The pinion drives through the glued horn, so use CA or epoxy.
3. The module thickness is assumed at 3.0 mm. Measure your module and change MODULE_T in the script if it differs.
4. The hard stop is at 33.5 mm. If you command the servo past 160 degrees it stalls against the stop.
5. The rack guide tube is only about 5 mm long, because the pinion sits just below it. The second column (guide) does most of the alignment, so the cap may rock slightly.
6. Servo ear bosses are 4.6 mm across with a 2.0 mm pilot. They may crack, so use a drop of glue.
7. The eye windows are 17.6 mm circular holes in a horizontal wall. Print them with bridging care, or add supports.
8. Horns are held by two pins and glue only, on a 1.0 mm deep pocket in a 1.7 mm crown.
9. Side screws go through the arms. The arm surface is round, so expect the heads to sit unevenly. A small flat or a washer helps.
10. Cable pull is taken by the cable tie on the plug overmold and the tub. The tie holes and saddle blocks assume a plug overmold about 12 mm wide and 7 mm tall.
13. The front screws sit just below the lock tips and are visible with the cap closed. A dab of slate paint hides them.
11. The cable lane and connector pocket are guesses. Check the fit with real wire.
12. No battery. Power is USB only.
