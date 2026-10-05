"""Fringe lock outlines shared by the Blender model and the flat marks.

Each lock is a tapered strand with a rounded tip, hanging from the fringe edge at z = edge.
Uneven lengths and a slight sideways sweep keep the edge reading as hair, never as eyes.
"""

import math

SWEEP_DEGREES = 13.0
TIP_RADIUS = 2.8
FRONT = [(-15.0, 6.5), (-7.5, 9.5), (0.0, 7.0), (7.5, 10.0), (15.0, 7.0)]
SIDE = [(-8.5, 7.0), (0.0, 9.0), (8.5, 6.5)]
WIDTH = 9.4


def lock_outline(center, length, edge, overlap=2.0, steps=28):
    """Convex outline (u, z) of one lock: flat top inside the fringe, rounded tip below the edge."""
    tip_u = center + length * math.tan(math.radians(SWEEP_DEGREES))
    tip_z = edge - length + TIP_RADIUS
    points = [(center - WIDTH / 2, edge + overlap), (center + WIDTH / 2, edge + overlap)]
    for i in range(steps + 1):
        angle = -math.pi * i / steps
        points.append((tip_u + TIP_RADIUS * math.cos(angle), tip_z + TIP_RADIUS * math.sin(angle)))
    return points
