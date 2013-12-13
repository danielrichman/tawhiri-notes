while not k(x, t):
    # ODE-solve horizontally
    x_dot = g(x, t)
    assert x_dot[2] == 0
    x += x_dot * dt

    # Update the altitude
    x[2] = h(t)
