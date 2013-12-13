def make_f(models): return lambda x, t: sum_pointwise(model(x, t) for model in models)
