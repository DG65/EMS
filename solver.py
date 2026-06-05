from flask import Flask,request,jsonify
from ortools.linear_solver import pywraplp

app = Flask(__name__)

@app.route("/optimize",methods=["POST"])
def optimize():
    d=request.json
    price=d["price"]
    pv=d["pv"]
    load=d["load"]
    evE=d["ev_energy"]
    evD=d["ev_deadline"]
    T=len(price)

    s=pywraplp.Solver.CreateSolver("GLOP")

    cap=d["config"]["capacity"]
    smin=d["config"]["soc_min"]*cap
    smax=d["config"]["soc_max"]*cap
    grid=d["config"]["grid_limit"]
    dt=0.25

    soc=[s.NumVar(smin,smax,f"soc{t}") for t in range(T)]
    c=[s.NumVar(0,24,f"c{t}") for t in range(T)]
    dch=[s.NumVar(0,24,f"d{t}") for t in range(T)]
    imp=[s.NumVar(0,grid,f"i{t}") for t in range(T)]
    exp=[s.NumVar(0,grid,f"e{t}") for t in range(T)]
    ev1=[s.NumVar(0,11,f"ev1{t}") for t in range(T)]
    ev2=[s.NumVar(0,11,f"ev2{t}") for t in range(T)]

    s.Add(soc[0]==d["battery_soc"]/100*cap)

    for t in range(T-1):
        s.Add(soc[t+1]==soc[t]+c[t]*0.9*dt-dch[t]/0.9*dt)

    # deadlines
    s.Add(sum(ev1[t]*dt for t in range(evD[0]))>=evE[0])
    s.Add(sum(ev2[t]*dt for t in range(evD[1]))>=evE[1])

    # balance
    for t in range(T):
        s.Add(pv[t]+imp[t]+dch[t]==c[t]+exp[t]+ev1[t]+ev2[t]+load[t])

    obj=s.Objective()
    feed=0.1836

    for t in range(T):
        p=price[t]/100
        obj.SetCoefficient(imp[t],p*dt)
        obj.SetCoefficient(exp[t],-feed*dt)

    obj.SetMinimization()
    s.Solve()

    return jsonify([{"t":t,"soc":soc[t].solution_value(),"ev1":ev1[t].solution_value(),"ev2":ev2[t].solution_value()} for t in range(T)])

if __name__=="__main__":
    app.run(host="0.0.0.0",port=5000)
