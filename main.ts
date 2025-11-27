import express from "express";
import { spawn } from "child_process";

const app = express();
app.use(express.json());

app.get("/", (req, res) => {
  res.send("Hello, Worltd!");
});

const PORT = process.env.PORT || 5000;
app.listen(PORT, "0.0.0.0", () => console.log(`listening on ${PORT}`));
