while not k(x, t):
    x_dot = f(x, t)
    x += x_dot * dt
