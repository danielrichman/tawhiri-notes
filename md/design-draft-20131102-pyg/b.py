def make_f(models): return lambda x, t: sum(model(x, t) for model in models)
