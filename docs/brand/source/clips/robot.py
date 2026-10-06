"""The Robot at 120 bpm (12 frames per beat): every move snaps into place in 3 frames with a 1-frame overshoot jitter,
then holds dead still until the next click. Arm snaps, a forearm wave, torso twists, head bob, squash pops and a
45 degree turn in 15 degree clicks, all returning to rest."""

FRAMES = 168
CAMERA = {"direction": (1.2, -1.0, 0.14), "look_at": (0, 0, 32), "height": 105.0}
BEAT_FRAMES = 12
PROFILE = [0.0, 0.5, 0.92, 1.05]
LEAD_FRAMES = 3

ARM_R = [(0, (0, 0)), (1, (90, 0)), (3, (135, 0)), (4, (45, 0)), (5, (90, 0)), (12, (0, 0))]
ARM_L = [(0, (0, 0)), (2, (90, 0)), (3.5, (135, 0)), (4.5, (45, 0)), (5, (90, 0)), (12.5, (0, 0))]
TWIST = [(0, 0), (5.5, 30), (6, 0), (6.5, -30), (7, 0)]
BEND = [(0, 0), (7.5, 14), (8, 0), (8.5, 14), (9, 0)]
SQUASH = [(0, (0, 0)), (9.5, (0.2, 0)), (10, (-0.2, 3)), (10.5, (0, 0))]
TURN = [(0, 0), (10.5, 15), (11, 30), (11.5, 45), (12, 30), (12.5, 15), (13, 0)]


def snap(frame, events):
    """Value at frame for [(beat, value)] events; values are numbers or tuples. A move starts LEAD_FRAMES before its beat."""
    current = events[0][1]
    for index in range(1, len(events)):
        start = events[index][0] * BEAT_FRAMES - LEAD_FRAMES
        if frame < start:
            break
        previous, target = events[index - 1][1], events[index][1]
        step = int(frame - start)
        amount = PROFILE[step] if step < len(PROFILE) else 1.0
        if isinstance(target, tuple):
            current = tuple(a + (b - a) * amount for a, b in zip(previous, target))
        else:
            current = previous + (target - previous) * amount
    return current


def animate(rig, frame):
    squash, drop = snap(frame, SQUASH)
    rig.pose(
        turn=snap(frame, TURN), twist=snap(frame, TWIST), bend=snap(frame, BEND),
        squash=squash, hip_drop=drop,
        arm_l=snap(frame, ARM_L), arm_r=snap(frame, ARM_R),
    )
