"""Six still poses that show each joint's direction, for checking the rig."""

FRAMES = 6
POSES = [
    {},
    {"arm_l": (90,), "arm_r": (0, 90)},
    {"arm_l": (90, 0, 60), "arm_r": (170, 0, 0, 1.6)},
    {"leg_l": (30,), "leg_r": (-30,), "lean": 10},
    {"leg_r": (0, 0, 0, 25), "twist": 30, "bend": 15, "nod": 10, "tilt": 10},
    {"turn": 90, "squash": 0.15, "lift": 10, "horn_droop": 20},
]


def animate(rig, frame):
    rig.pose(**POSES[frame])
